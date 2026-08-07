<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use cstudiossro\craftcschatbot\events\BuildSystemPromptEvent;
use cstudiossro\craftcschatbot\events\TransformReplyEvent;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatMessageRecord;
use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use cstudiossro\craftcschatbot\records\SuggestionStatRecord;
use yii\base\Component;

class Chat extends Component
{
    /**
     * @event BuildSystemPromptEvent Fired while assembling the system prompt,
     * so plugins/modules can append their own context blocks.
     */
    public const EVENT_BUILD_SYSTEM_PROMPT = 'buildSystemPrompt';

    /**
     * @event TransformReplyEvent Fired after the model replies but before the
     * text is logged/returned, so plugins/modules can rewrite it (e.g. resolve
     * link tokens to real URLs).
     */
    public const EVENT_TRANSFORM_REPLY = 'transformReply';


    public function getOrCreateSession(?string $token, ?string $pageUrl = null): ChatSessionRecord
    {
        $settings = Plugin::getInstance()->getSettings();
        if ($token) {
            $session = ChatSessionRecord::findOne(['sessionToken' => $token]);
            if ($session) {
                if ($pageUrl) {
                    $session->pageUrl = $pageUrl;
                }
                $session->save(false);
                return $session;
            }
        }
        // Console/queue contexts have no client request — a chat can legitimately
        // be driven from the CLI (evaluation runs, replaying a conversation), so
        // read request details defensively instead of assuming a web request.
        $request = Craft::$app->getRequest();
        $isWeb = $request instanceof \craft\web\Request;

        $session = new ChatSessionRecord();
        $session->sessionToken = StringHelper::randomString(48);
        // IP always stored — needed for bans even if logging disabled
        $session->ip = $isWeb ? $request->getUserIP() : null;
        if ($settings->loggingEnabled) {
            $ua = $isWeb ? $request->getUserAgent() : null;
            $session->userAgent = $ua ? substr($ua, 0, 512) : null;
            $session->pageUrl = $pageUrl;
        }
        $session->save(false);
        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function ask(string $question, ?string $sessionToken = null, ?string $pageUrl = null): array
    {
        $session = $this->getOrCreateSession($sessionToken, $pageUrl);

        // Throttle bot mode: if the previous message in this session is a user
        // message without a bot reply yet, that turn is still in flight — reject
        // this incoming one. Only while it *could* still be in flight, though: a
        // turn that died (API timeout, fatal) leaves the same orphan row behind
        // forever, and an unbounded check would lock the visitor out of their own
        // conversation permanently. Skip entirely for handoff sessions, where
        // humans are slow on purpose.
        if (!in_array($session->handoffStatus, ['requested', 'active'], true)) {
            $last = (new \craft\db\Query())
                ->select(['role', 'dateCreated'])
                ->from('{{%chatbot_messages}}')
                ->where(['sessionId' => $session->id])
                ->andWhere(['in', 'role', ['user', 'bot', 'admin']])
                ->orderBy(['id' => SORT_DESC])
                ->limit(1)
                ->one();
            if (($last['role'] ?? null) === 'user' && $this->turnStillInFlight($last['dateCreated'] ?? null)) {
                throw new \RuntimeException('Please wait for the previous reply.');
            }
        }

        $userMsg = $this->logMessage($session, 'user', $question, null, null);
        $session->messageCount = (int)$session->messageCount + 1;
        $session->save(false);

        // Handoff active or requested — skip LLM, message will be delivered to admin via polling
        if (in_array($session->handoffStatus, ['requested', 'active'], true)) {
            return [
                'handoff' => true,
                'handoffStatus' => $session->handoffStatus,
                'sessionToken' => $session->sessionToken,
                'userMessageId' => (int)$userMsg->id,
            ];
        }

        try {
            return $this->generateReply($session, $question);
        } catch (\Throwable $e) {
            // The turn died before any reply was logged (API timeout, fatal in a
            // tool call). Roll the user message back so the in-flight check above
            // can't lock the visitor out of their own conversation for good.
            $this->discardFailedTurn($session, $userMsg);
            throw $e;
        }
    }

    /**
     * How long a logged user message with no reply yet is treated as a turn
     * still being generated. Past this the turn is assumed dead and a new
     * message is let through. Covers the OpenAI client timeout plus a bounded
     * tool-calling loop.
     */
    private const IN_FLIGHT_SECONDS = 180;

    private function turnStillInFlight(?string $utcDateCreated): bool
    {
        if (!$utcDateCreated) {
            return false;
        }
        $ts = strtotime($utcDateCreated . ' UTC');
        if ($ts === false) {
            return false;
        }
        return (time() - $ts) < self::IN_FLIGHT_SECONDS;
    }

    /**
     * Undo the bookkeeping of a turn that threw before logging a reply, so the
     * conversation is left exactly as it was before the failed attempt.
     */
    private function discardFailedTurn(ChatSessionRecord $session, ChatMessageRecord $userMsg): void
    {
        try {
            $userMsg->delete();
            $session->messageCount = max(0, (int)$session->messageCount - 1);
            $session->save(false);
        } catch (\Throwable $e) {
            Craft::error('Could not roll back failed turn: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Retrieve context, generate the reply, log it and assemble the widget
     * payload. Split out of {@see self::ask()} so a turn that throws part-way
     * has exactly one rollback point.
     *
     * @return array<string, mixed>
     */
    private function generateReply(ChatSessionRecord $session, string $question): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $plugin = Plugin::getInstance();
        $start = microtime(true);

        // Conversation so far (excludes the user message we just logged). Reused
        // for both building the retrieval query and generation further down.
        $history = $this->recentHistory($session->id, max(2, (int)$settings->historyMessages));

        // Resolve the site up front so retrieval can filter to it (a Slovak-site
        // query shouldn't pull English chunks). Reused for the system prompt below.
        $site = \cstudiossro\craftcschatbot\models\Settings::resolveSiteFromUrl($session->pageUrl);
        $siteUid = $site->uid ?? null;
        $siteId = isset($site->id) ? (int)$site->id : null;

        // Turn the (possibly elliptical) latest message into a standalone
        // retrieval query and decide whether this turn needs retrieval at all.
        [$retrievalQuery, $needsRetrieval, $outOfScope] = $this->buildRetrievalQuery($history, $question);
        $guarded = !$needsRetrieval;

        $hits = [];
        $usableHits = [];
        $confidence = 0.0;
        if (!$guarded) {
            // embed the standalone query, not the raw follow-up
            $qVecArr = $plugin->openAi->embed([$retrievalQuery]);
            $qVec = $qVecArr[0] ?? [];

            // Retrieve a wider candidate pool, then rerank down to the context size.
            $pool = max((int)$settings->retrievalCandidatePool, (int)$settings->maxContextChunks);
            $needVectors = $settings->rerankMode === 'mmr';
            $candidates = $plugin->vectorSearch->topK($qVec, $pool, 0.0, $retrievalQuery, $needVectors, $siteId);
            $hits = $this->rerank($candidates, $retrievalQuery);

            // Rows come back in fused rank order, so the best cosine isn't
            // necessarily first — take the maximum for both the confidence
            // signal and the relative floor.
            $confidence = !empty($hits) ? max(array_map(fn($h) => (float)$h['score'], $hits)) : 0.0;
            $floor = (float)$settings->minSimilarityScore;
            if ($settings->relativeScoreFloor > 0) {
                $floor = max($floor, $confidence * (float)$settings->relativeScoreFloor);
            }
            $usableHits = array_filter($hits, fn($h) => $h['score'] >= $floor);
        }

        $contextBlocks = [];
        $allowedUrls = [];
        // array_filter preserved the original keys; renumber so the citation
        // markers the model sees are contiguous.
        foreach (array_values($usableHits) as $i => $h) {
            $url = $this->resolveSourceUrl($h['sourceType'], (int)$h['sourceId']);
            if ($url) {
                $allowedUrls[$this->normalizeUrl($url)] = true;
            }
            $head = $url
                ? sprintf("[%d] (%s) URL: %s", $i + 1, $h['sourceType'], $url)
                : sprintf("[%d] (%s)", $i + 1, $h['sourceType']);
            $contextBlocks[] = $head . "\n" . $h['content'];
        }
        $context = implode("\n\n---\n\n", $contextBlocks);

        $systemPrompt = $settings->getSystemPromptForSite($siteUid);

        // Let plugins/modules contribute extra context (e.g. the current date on
        // an events site). Core stays generic — site-specific additions live in
        // the listeners, not here.
        $promptEvent = new BuildSystemPromptEvent([
            'siteUid' => $siteUid,
            'question' => $question,
            'session' => $session,
        ]);
        $this->trigger(self::EVENT_BUILD_SYSTEM_PROMPT, $promptEvent);
        foreach ($promptEvent->additions as $addition) {
            $addition = trim((string)$addition);
            if ($addition !== '') {
                $systemPrompt .= "\n\n" . $addition;
            }
        }

        // Language. Without this the model follows whichever language dominates
        // the prompt — the site's own content and its opening greeting — and
        // answers an English visitor in the site's language.
        $siteLanguage = $site->language ?? Craft::$app->language;
        $systemPrompt .= "\n\n# Language\nAlways reply in the same language the visitor wrote their latest message in, even when the context passages, this prompt and the rest of the conversation are in another language. Translate what you find in the context rather than quoting it in its original language — except for names, addresses, product names and other proper nouns, which stay as written. If the visitor's language is genuinely unclear, use `{$siteLanguage}`.";

        // Retrieval returns the nearest passages, not necessarily relevant ones.
        // Told nothing, the model treats everything it was handed as material it
        // ought to use, and pads answers with whatever came back.
        $systemPrompt .= "\n\n# Using the context\nThe context below was selected by similarity search, so some passages will have nothing to do with the question. Answer only from the passages that genuinely address it and ignore the rest — never list or mention unrelated items to fill out an answer. If none of the passages answer the question, say you don't have that information instead of offering the nearest thing you were given.";

        $systemPrompt .= "\n\n# Output format\nFormat all replies as GitHub-flavored Markdown. Use **bold**, *italic*, `inline code`, fenced code blocks with language tags, bullet/numbered lists (including nested ones), pipe tables when you are comparing two or more things across the same handful of attributes, headings (## or ###), and [links](https://example.com) where they aid clarity. Do not wrap the whole answer in a code block. Keep formatting purposeful — short answers stay short.\n\nWhen you suggest a page from the site for the user to read, put the link on its own line as `[Title](url)`, using the exact `URL:` value given for the relevant context block below. Never invent, guess, or use placeholder URLs — only link to a page when its real URL is present in the context. The UI renders such standalone links as a rich preview card with the page's title, description and image — so place at most one per paragraph. Never print a bare URL or path as plain text: either write it as a Markdown link or leave it out.";
        // Starter prompts double as the only machine-readable statement of what
        // this assistant is for. Without them a turn that retrieves nothing —
        // a greeting, an off-topic opener — leaves the model to invent a menu of
        // services the site may not offer.
        $canHelpWith = $settings->getSuggestionsForSite($siteUid);
        if (!empty($canHelpWith)) {
            $systemPrompt .= "\n\n# What visitors ask you\nThese are the questions this assistant is set up to answer. Treat them as the shape of your job: when you have to describe what you can help with, describe these, and never advertise a service the site has not shown you evidence of.\n- "
                . implode("\n- ", array_map(fn($s) => trim((string)$s), $canHelpWith));
        }

        if ($settings->humanHandoffEnabled) {
            $systemPrompt .= "\n\n# Handoff signal\nWhenever you (a) cannot answer the user's question from the provided context, (b) are uncertain, or (c) the user is explicitly asking for a human, append the exact literal token `[[HANDOFF_OFFER]]` on its own line at the very end of your reply. Do not translate, modify, paraphrase, or comment on this token. Do not output it in any other situation — in particular, never offer a human for a request that falls outside what this assistant is for, because a person cannot help with those either. The UI strips the token and uses it to show a 'Talk to a human' button — language does not matter.";
        }
        // Skills (tool-calling). Respect per-skill availability: 'admins' skills
        // are exposed only to logged-in CP users so they can be tested live.
        $isCpUser = !Craft::$app->getUser()->getIsGuest()
            && Craft::$app->getUser()->checkPermission('accessCp');
        $enabledCaps = ($settings->agentModeEnabled && !$plugin->capabilities->isEmpty())
            ? $plugin->capabilities->enabledFor($isCpUser)
            : [];
        if (!empty($enabledCaps)) {
            $systemPrompt .= "\n\n# Tools\nYou can call the provided tools to fetch live data or perform actions. Use them only when they help answer the question, then reply normally using their results. Do not mention the tools themselves.";
        }

        // Conversational forms: nudge the model to offer relevant forms proactively
        // (like a salesperson) and, critically, to never alter the visitor's answers.
        $formCaps = array_filter(
            $enabledCaps,
            fn($c) => $c instanceof \cstudiossro\craftcschatbot\capabilities\ConfiguredFormCapability
        );
        if ($settings->formsEnabled && !empty($formCaps)) {
            $formList = '';
            foreach ($formCaps as $fc) {
                $formList .= "\n- `" . $fc->name() . "`: " . $fc->description();
            }
            $systemPrompt .= "\n\n# Forms\nYou can offer these forms by calling the matching tool:" . $formList
                . "\n\nBehave like an attentive salesperson: when the conversation shows the visitor could benefit from one of these (they express interest, a need, or intent it serves), proactively offer it — do not wait to be asked. Offer at most one form at a time, only when clearly relevant, and never be pushy; if the user declines, drop it gracefully."
                . "\n\nEach tool's description says how it works. For forms you fill yourself, collect the fields by asking a question or two at a time, briefly recap them for confirmation, and copy every value EXACTLY as the user gave it — never rephrase, translate, correct, reformat, complete, summarize, or invent any value; if unsure, ask. For forms that are displayed for the user to complete, just call the tool when relevant and tell the user to fill in the form shown — do not ask for the values yourself.";
        }

        if ($context !== '') {
            $systemPrompt .= "\n\n# Context\n" . $context;
        } else {
            $systemPrompt .= "\n\n# Context\n(none)";
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        // The widget shows an initial greeting before the user types, but that
        // message lives only client-side — it's never logged to history. On the
        // first user turn (empty history) the model has no idea it already
        // greeted, so it greets again. Seed it as the opening assistant turn so
        // the model continues the conversation instead of re-greeting.
        if (empty($history)) {
            $greeting = trim($settings->getInitialMessageForSite($siteUid));
            if ($greeting !== '') {
                $messages[] = ['role' => 'assistant', 'content' => $greeting];
            }
        }
        foreach ($history as $h) {
            // A human agent's turn reached the visitor as the assistant speaking.
            $isAssistant = in_array($h['role'], ['bot', 'admin'], true);
            $messages[] = ['role' => $isAssistant ? 'assistant' : 'user', 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $tools = !empty($enabledCaps) ? $plugin->capabilities->toolSchemas($enabledCaps) : [];
        // Give form capabilities the session they were collected in, so a
        // submission made during the tool loop links back to this chat.
        $plugin->forms->setCurrentSession($session);
        $reply = $this->complete($messages, $tools);
        $responseTime = round(microtime(true) - $start, 3);

        // Sentinel token from the model = language-agnostic "offer human" signal. Strip before showing.
        $hasHandoffToken = str_contains($reply, '[[HANDOFF_OFFER]]');
        if ($hasHandoffToken) {
            $reply = trim(preg_replace('/\s*\[\[HANDOFF_OFFER\]\]\s*/u', '', $reply) ?? $reply);
        }

        // Guard against hallucinated links. The model is told to only link URLs
        // present in the context, but it sometimes invents on-site pages that 404.
        // Unlink any on-site URL that wasn't in the retrieved context (keep the
        // visible text). Runs BEFORE the transform event so token→URL resolutions
        // (which the model never sees as URLs) stay trusted. Off-site URLs and
        // exact context URLs are left intact.
        $reply = $this->stripHallucinatedLinks($reply, $allowedUrls);

        // Let plugins/modules rewrite the reply (e.g. resolve event-link tokens
        // to real URLs). The model only ever emits short tokens, never long
        // URLs it might mistype.
        $transformEvent = new TransformReplyEvent([
            'reply' => $reply,
            'question' => $question,
            'siteUid' => $siteUid,
            'session' => $session,
        ]);
        $this->trigger(self::EVENT_TRANSFORM_REPLY, $transformEvent);
        $reply = $transformEvent->reply;

        $botMsg = $this->logMessage($session, 'bot', $reply, $confidence, $responseTime);

        // low-confidence streak tracking → offer help (human handoff and/or contact capture).
        // Guarded chit-chat turns (no retrieval) carry no confidence signal, so they
        // neither add to nor reset the streak.
        $offerHuman = false;
        if (!$guarded) {
            if ($confidence < $settings->minSimilarityScore) {
                $session->lowConfStreak = (int)$session->lowConfStreak + 1;
                if ($session->lowConfStreak >= 2) {
                    $offerHuman = true;
                }
            } else {
                $session->lowConfStreak = 0;
            }
        }
        if ($hasHandoffToken) {
            $offerHuman = true;
        }
        // A request the assistant should not serve at all is not one a colleague
        // can serve either — routing it to the live-chat queue only wastes an
        // agent's time. The guard excludes plain "I want to speak to someone".
        if ($outOfScope) {
            $offerHuman = false;
        }
        // Nothing to offer if both human handoff and contact capture are off.
        if (!$settings->humanHandoffEnabled && !$settings->contactCaptureEnabled) {
            $offerHuman = false;
        }
        if ($offerHuman) {
            $botMsg->offerHuman = true;
            $botMsg->save(false);
        }
        $session->messageCount = (int)$session->messageCount + 1;
        $session->save(false);

        // Inline-mode form the model asked to display this turn (schema only,
        // no delivery config) — the widget renders it for the user to submit.
        $formToShow = $plugin->forms->consumeFormToShow();

        return [
            'reply' => $reply,
            'confidence' => $confidence,
            'responseTime' => $responseTime,
            'messageId' => (int)$botMsg->id,
            'sessionToken' => $session->sessionToken,
            'sessionId' => (int)$session->id,
            'shortId' => sprintf('%05d-%s', (int)$session->id, strtoupper(substr((string)$session->sessionToken, 0, 4))),
            'offerHuman' => $offerHuman,
            'form' => $formToShow,
        ];
    }

    /**
     * Canonical key for comparing URLs: lowercase host, no trailing slash,
     * fragment/default-port dropped. Query kept (distinct pages may share a path).
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return rtrim($url, '/');
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * Lowercased hostnames of every Craft site — used to tell on-site links
     * (guarded) from external ones (left alone).
     *
     * @return array<string, true>
     */
    private function siteHosts(): array
    {
        $hosts = [];
        foreach (Craft::$app->sites->getAllSites() as $st) {
            $host = parse_url((string)$st->getBaseUrl(), PHP_URL_HOST);
            if ($host) {
                $hosts[strtolower($host)] = true;
            }
        }
        return $hosts;
    }

    /**
     * Unlink on-site markdown links whose URL isn't in the retrieved context,
     * keeping the visible text. Off-site links and exact context URLs pass through.
     *
     * @param array<string, true> $allowedUrls normalized context URLs
     */
    private function stripHallucinatedLinks(string $reply, array $allowedUrls): string
    {
        if (!str_contains($reply, 'http')) {
            return $reply;
        }
        $hosts = $this->siteHosts();
        if (empty($hosts)) {
            return $reply;
        }
        $onSiteAllowed = function (string $url) use ($allowedUrls, $hosts): ?bool {
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            // null = off-site (not ours to police). true/false = on-site verdict.
            if ($host === '' || !isset($hosts[$host])) {
                return null;
            }
            return isset($allowedUrls[$this->normalizeUrl($url)]);
        };

        // Markdown links: drop the link but keep the visible words.
        $reply = preg_replace_callback(
            '/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u',
            function (array $m) use ($onSiteAllowed): string {
                $verdict = $onSiteAllowed($m[2]);
                if ($verdict === null || $verdict === true) {
                    return $m[0];
                }
                Craft::info("Stripped hallucinated link: {$m[2]}", 'cs-chatbot');
                return $m[1];
            },
            $reply
        ) ?? $reply;

        // Bare URLs (no markdown syntax — the widget would autolink these). Skip
        // any preceded by "](" so we don't touch the markdown links kept above.
        $reply = preg_replace_callback(
            '/(?<!\]\()(?<![\w@])(https?:\/\/[^\s<>()\[\]]+)/u',
            function (array $m) use ($onSiteAllowed): string {
                $url = rtrim($m[1], '.,;:!?');
                $trail = substr($m[1], strlen($url));
                $verdict = $onSiteAllowed($url);
                if ($verdict === null || $verdict === true) {
                    return $m[1];
                }
                Craft::info("Stripped hallucinated bare URL: {$url}", 'cs-chatbot');
                return $trail;
            },
            $reply
        ) ?? $reply;

        return $reply;
    }

    /**
     * Resolve a public URL for a retrieved chunk, keyed by its source.
     * chunk.sourceId points at the training record id, not the element id.
     */
    private function resolveSourceUrl(string $sourceType, int $sourceId): ?string
    {
        try {
            switch ($sourceType) {
                case 'entry':
                    $rec = \cstudiossro\craftcschatbot\records\TrainingEntryRecord::findOne($sourceId);
                    if (!$rec) {
                        return null;
                    }
                    $entry = \craft\elements\Entry::find()
                        ->id($rec->entryId)->siteId($rec->siteId)->status(null)->one();
                    return $entry?->getUrl();
                case 'category':
                    $rec = \cstudiossro\craftcschatbot\records\TrainingCategoryRecord::findOne($sourceId);
                    if (!$rec) {
                        return null;
                    }
                    $cat = \craft\elements\Category::find()
                        ->id($rec->categoryId)->siteId($rec->siteId)->status(null)->one();
                    return $cat?->getUrl();
                case 'url':
                    $rec = \cstudiossro\craftcschatbot\records\TrainingUrlRecord::findOne($sourceId);
                    return $rec?->url ?: null;
                default:
                    // file, global, qa have no public URL
                    return null;
            }
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Produce the assistant reply. With no tools this is a single completion;
     * with tools it runs a bounded tool-calling loop: the model may request
     * tool calls, we execute them via the Capabilities registry, feed the
     * results back, and repeat until it answers or the iteration cap is hit.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     */
    private function complete(array $messages, array $tools): string
    {
        $plugin = Plugin::getInstance();
        if (empty($tools)) {
            return $plugin->openAi->chat($messages);
        }
        $caps = $plugin->capabilities;
        $maxIter = max(1, (int)$plugin->getSettings()->maxToolIterations);
        for ($i = 0; $i < $maxIter; $i++) {
            $message = $plugin->openAi->chatRaw($messages, ['tools' => $tools]);
            $calls = $message['tool_calls'] ?? [];
            if (empty($calls)) {
                return (string)($message['content'] ?? '');
            }
            // The assistant message carrying tool_calls must precede the results.
            $messages[] = $message;
            foreach ($calls as $call) {
                $fn = (string)($call['function']['name'] ?? '');
                $argsRaw = $call['function']['arguments'] ?? '{}';
                $args = json_decode(is_string($argsRaw) ? $argsRaw : '{}', true);
                $result = $caps->run($fn, is_array($args) ? $args : []);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string)($call['id'] ?? ''),
                    'content' => json_encode($result),
                ];
            }
        }
        // Hit the iteration cap with calls still pending — force a final answer.
        $message = $plugin->openAi->chatRaw($messages);
        return (string)($message['content'] ?? '');
    }

    private function logMessage(ChatSessionRecord $session, string $role, string $content, ?float $confidence, ?float $responseTime): ChatMessageRecord
    {
        $rec = new ChatMessageRecord();
        $rec->sessionId = (int)$session->id;
        $rec->role = $role;
        $rec->content = $content;
        $rec->confidence = $confidence;
        $rec->responseTime = $responseTime;
        if (!Plugin::getInstance()->getSettings()->loggingEnabled) {
            // still persist minimal so we can rate, but strip content
            $rec->content = $role === 'user' ? '[redacted]' : $content;
            // The model still needs the real words to follow the conversation.
            // Keep them out of the transcript the CP shows and out of the
            // retention window, in a short-lived cache entry instead.
            $this->pushContextCache((int)$session->id, $role, $content);
        }
        $rec->save(false);
        return $rec;
    }

    /**
     * Where the conversation is kept for the model when transcript logging is
     * off. Short-lived on purpose: long enough to finish a conversation, not
     * long enough to be a record of one.
     */
    private const CONTEXT_CACHE_TTL = 7200;

    private function contextCacheKey(int $sessionId): string
    {
        return 'cs-chatbot:context:' . $sessionId;
    }

    /**
     * @param array<int, array{role:string, content:string}> $messages
     */
    /**
     * Record a turn this service did not log itself — a live-chat agent's reply —
     * so the model still sees it when transcript logging is off and history
     * therefore comes from the cache rather than the database.
     */
    public function rememberForContext(ChatSessionRecord $session, string $role, string $content): void
    {
        if (!Plugin::getInstance()->getSettings()->loggingEnabled) {
            $this->pushContextCache((int)$session->id, $role, $content);
        }
    }

    private function pushContextCache(int $sessionId, string $role, string $content): void
    {
        $cache = Craft::$app->getCache();
        $key = $this->contextCacheKey($sessionId);
        $messages = $cache->get($key);
        $messages = is_array($messages) ? $messages : [];
        $messages[] = ['role' => $role, 'content' => $content];
        // Bounded so a long conversation can't grow one cache entry without limit.
        $messages = array_slice($messages, -40);
        $cache->set($key, $messages, self::CONTEXT_CACHE_TTL);
    }

    /**
     * The turns before the message currently being answered, oldest first.
     *
     * @return array<int, array{role:string, content:string}>
     */
    private function recentHistory(int $sessionId, int $limit): array
    {
        if (!Plugin::getInstance()->getSettings()->loggingEnabled) {
            $cached = Craft::$app->getCache()->get($this->contextCacheKey($sessionId));
            $messages = is_array($cached) ? $cached : [];
        } else {
            $messages = array_reverse((new \craft\db\Query())
                ->select(['role', 'content'])
                ->from('{{%chatbot_messages}}')
                ->where(['sessionId' => $sessionId])
                ->andWhere(['in', 'role', ['user', 'bot', 'admin']])
                ->orderBy(['id' => SORT_DESC])
                ->limit($limit + 1)
                ->all());
        }
        // Drop the trailing message — that's the one we're answering right now.
        array_pop($messages);
        return array_slice($messages, -$limit);
    }

    /**
     * Turn the latest user message into a standalone retrieval query using prior
     * turns (resolving pronouns/ellipsis), and decide whether this turn needs a
     * knowledge-base lookup at all.
     *
     * The rewrite only affects the retrieval embedding — generation still sees
     * the user's original message. On the first turn, or on any failure, this
     * falls back to the raw question with retrieval on, so search never silently
     * breaks.
     *
     * @param array<int, array{role:string, content:string}> $history
     * @return array{0:string, 1:bool, 2:bool} [standaloneQuery, needsRetrieval, outOfScope]
     */
    private function buildRetrievalQuery(array $history, string $question): array
    {
        $settings = Plugin::getInstance()->getSettings();

        // Cheap heuristic guard for pure smalltalk (works without any LLM call).
        $smalltalk = $settings->retrievalGuardEnabled && $this->looksLikeSmalltalk($question);

        // Nothing to ask the model for: no rewriting wanted and no guard to run.
        if (!$settings->queryRewriteEnabled && !$settings->retrievalGuardEnabled) {
            return [$question, true, false];
        }

        try {
            $convo = '';
            foreach ($history as $h) {
                $role = ($h['role'] ?? '') === 'bot' ? 'Assistant' : 'User';
                $convo .= $role . ': ' . trim((string)($h['content'] ?? '')) . "\n";
            }
            $sys = "You rewrite a chat user's latest message into a standalone search query "
                . "for a knowledge base. Resolve pronouns and ellipsis using the conversation. "
                . "Keep it concise and in the user's language. Also decide whether answering needs "
                . "a knowledge-base lookup at all — greetings, thanks, pure chit-chat and requests "
                . "that have nothing to do with a website's own content (writing code, general "
                . "trivia, homework) do not. Finally, set out_of_scope when the message asks for "
                . "something a website assistant should not do at all — but never for a message "
                . "that simply asks to speak to a person. "
                . 'Respond with ONLY compact JSON: '
                . '{"query": string, "needs_retrieval": boolean, "out_of_scope": boolean}.';
            // The conversation may be empty — this runs on the first turn too,
            // which is exactly where an off-topic opener arrives and where
            // skipping the check used to pull a full context set for nothing.
            $usr = ($convo !== '' ? "Conversation so far:\n" . trim($convo) . "\n\n" : '')
                . 'Latest user message: ' . $question;
            $msg = Plugin::getInstance()->openAi->chatRaw(
                [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $usr],
                ],
                ['model' => $settings->helperModel, 'temperature' => 0.0]
            );
            $data = json_decode($this->stripJsonFence((string)($msg['content'] ?? '')), true);
            if (is_array($data)) {
                $q = $settings->queryRewriteEnabled ? trim((string)($data['query'] ?? '')) : '';
                $q = $q !== '' ? $q : $question;
                $needs = !array_key_exists('needs_retrieval', $data) || (bool)$data['needs_retrieval'];
                $outOfScope = !empty($data['out_of_scope']);
                if ($settings->retrievalGuardEnabled) {
                    return [$q, $needs, $outOfScope];
                }
                return [$q, true, $outOfScope];
            }
        } catch (\Throwable) {
            // fall through to the safe default
        }
        return [$question, !$smalltalk, false];
    }

    /**
     * True when the message is nothing but greeting/thanks/acknowledgement tokens
     * (≤4 words, every word a known smalltalk term). Deliberately conservative —
     * it must never suppress a real question.
     */
    private function looksLikeSmalltalk(string $text): bool
    {
        $t = trim(mb_strtolower($text, 'UTF-8'));
        if ($t === '') {
            return true;
        }
        $t = trim((string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t));
        $words = array_values(array_filter(preg_split('/\s+/u', $t) ?: [], fn($w) => $w !== ''));
        if (count($words) === 0 || count($words) > 4) {
            return false;
        }
        // English + the locales this plugin already ships translations for (sk, hu).
        static $smalltalk = [
            'hi', 'hello', 'hey', 'yo', 'hiya', 'heya', 'greetings',
            'thanks', 'thank', 'thankyou', 'thx', 'ty', 'cheers',
            'ok', 'okay', 'k', 'cool', 'great', 'nice', 'perfect', 'awesome',
            'bye', 'goodbye', 'cya',
            'ahoj', 'cau', 'čau', 'dakujem', 'ďakujem', 'vdaka', 'vďaka', 'dovi', 'dovidenia',
            'szia', 'helló', 'hello', 'koszonom', 'köszönöm', 'köszi', 'szervusz', 'viszlat', 'viszlát',
        ];
        foreach ($words as $w) {
            if (!in_array($w, $smalltalk, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Strip a leading/trailing ```json fence some models wrap JSON in.
     */
    private function stripJsonFence(string $s): string
    {
        $s = trim($s);
        if (str_starts_with($s, '```')) {
            $s = (string)preg_replace('/^```[a-zA-Z]*\s*/', '', $s);
            $s = (string)preg_replace('/\s*```$/', '', $s);
        }
        return trim($s);
    }

    /**
     * Reduce a retrieved candidate pool to the final context set of
     * maxContextChunks. Modes: 'off' keeps the fused order, 'mmr' diversifies in
     * PHP, 'llm' scores with a cheap model call. Selection/order changes only —
     * each returned row keeps its raw-cosine `score` (the retrieval contract).
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function rerank(array $candidates, string $queryText): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $limit = max(1, (int)$settings->maxContextChunks);

        if (count($candidates) <= $limit || $settings->rerankMode === 'off') {
            $picked = array_slice($candidates, 0, $limit);
        } elseif ($settings->rerankMode === 'mmr') {
            $picked = $this->rerankMmr($candidates, $limit, (float)$settings->mmrLambda);
        } else {
            $picked = $this->rerankLlm($candidates, $queryText, $limit);
        }

        // Drop the internal embedding before rows reach the context loop.
        return array_map(function (array $row): array {
            unset($row['_vector']);
            return $row;
        }, $picked);
    }

    /**
     * LLM reranker: ask a cheap model to order the candidates by relevance and
     * take the top $limit. Falls back to fused order on any failure.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function rerankLlm(array $candidates, string $queryText, int $limit): array
    {
        try {
            $list = '';
            foreach ($candidates as $i => $c) {
                $snippet = trim((string)preg_replace('/\s+/', ' ', (string)($c['content'] ?? '')));
                $list .= "[{$i}] " . mb_substr($snippet, 0, 500) . "\n";
            }
            $sys = 'You rank knowledge-base passages by how well they help answer the user query. '
                . 'Return ONLY compact JSON: {"order": [indices]} listing the ' . $limit
                . ' most relevant passage indices, most relevant first. Use only indices shown.';
            $usr = "Query: {$queryText}\n\nPassages:\n" . $list;
            $msg = Plugin::getInstance()->openAi->chatRaw(
                [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $usr],
                ],
                ['model' => $settings->helperModel, 'temperature' => 0.0]
            );
            $data = json_decode($this->stripJsonFence((string)($msg['content'] ?? '')), true);
            $order = is_array($data['order'] ?? null) ? $data['order'] : null;
            if ($order) {
                $picked = [];
                $seen = [];
                foreach ($order as $idx) {
                    $idx = (int)$idx;
                    if (isset($candidates[$idx]) && !isset($seen[$idx])) {
                        $picked[] = $candidates[$idx];
                        $seen[$idx] = true;
                    }
                    if (count($picked) >= $limit) {
                        break;
                    }
                }
                if (!empty($picked)) {
                    return $picked;
                }
            }
        } catch (\Throwable) {
            // fall through to fused order
        }
        return array_slice($candidates, 0, $limit);
    }

    /**
     * Maximal Marginal Relevance: greedily pick chunks that are relevant to the
     * query (raw cosine `score`) while penalizing redundancy against those
     * already picked. Needs each candidate's `_vector`.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function rerankMmr(array $candidates, int $limit, float $lambda): array
    {
        $vectorSearch = Plugin::getInstance()->vectorSearch;
        $selected = [];
        $remaining = $candidates;
        while (!empty($remaining) && count($selected) < $limit) {
            $bestKey = null;
            $bestMmr = -INF;
            foreach ($remaining as $key => $cand) {
                $relevance = (float)($cand['score'] ?? 0.0);
                $redundancy = 0.0;
                $vec = $cand['_vector'] ?? null;
                if (is_array($vec) && !empty($selected)) {
                    foreach ($selected as $sel) {
                        $sv = $sel['_vector'] ?? null;
                        if (is_array($sv)) {
                            $sim = $vectorSearch->similarity($vec, $sv);
                            if ($sim > $redundancy) {
                                $redundancy = $sim;
                            }
                        }
                    }
                }
                $mmr = $lambda * $relevance - (1 - $lambda) * $redundancy;
                if ($mmr > $bestMmr) {
                    $bestMmr = $mmr;
                    $bestKey = $key;
                }
            }
            if ($bestKey === null) {
                break;
            }
            $selected[] = $remaining[$bestKey];
            unset($remaining[$bestKey]);
        }
        return $selected;
    }

    public function rateMessage(int $messageId, int $rating): bool
    {
        $rec = ChatMessageRecord::findOne($messageId);
        if (!$rec || $rec->role !== 'bot') {
            return false;
        }
        $prev = $rec->rating;
        $rec->rating = $rating === 0 ? null : ($rating > 0 ? 1 : -1);
        $rec->save(false);

        $session = ChatSessionRecord::findOne($rec->sessionId);
        if ($session) {
            $session->ratingPositive = (int)(new \craft\db\Query())
                ->from('{{%chatbot_messages}}')
                ->where(['sessionId' => $session->id, 'rating' => 1])
                ->count();
            $session->ratingNegative = (int)(new \craft\db\Query())
                ->from('{{%chatbot_messages}}')
                ->where(['sessionId' => $session->id, 'rating' => -1])
                ->count();
            $session->save(false);
        }
        return true;
    }

    public function recordSuggestionClick(string $suggestion): void
    {
        $rec = SuggestionStatRecord::findOne(['suggestion' => $suggestion]);
        if (!$rec) {
            $rec = new SuggestionStatRecord();
            $rec->suggestion = $suggestion;
            $rec->clicks = 0;
        }
        $rec->clicks = (int)$rec->clicks + 1;
        $rec->lastClickedAt = Db::prepareDateForDb(new \DateTime());
        $rec->save(false);
    }


    public static function formatLocalTime(?string $utcDate, string $format = 'H:i'): ?string
    {
        if (!$utcDate) {
            return null;
        }
        try {
            $d = new DateTime($utcDate, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
            return $d->format($format);
        } catch (\Throwable) {
            return null;
        }
    }

    public function endChat(string $sessionToken): bool
    {
        $session = ChatSessionRecord::findOne(['sessionToken' => $sessionToken]);
        if (!$session) {
            return false;
        }
        if ($session->chatEndedAt) {
            return true;
        }
        $session->chatEndedAt = Db::prepareDateForDb(new \DateTime());
        // close handoff too
        if (in_array($session->handoffStatus, ['requested', 'active'], true)) {
            $session->handoffStatus = 'ended';
            $session->handoffEndedAt = Db::prepareDateForDb(new \DateTime());
        }
        $session->save(false);
        $sysMsg = new ChatMessageRecord();
        $sysMsg->sessionId = (int)$session->id;
        $sysMsg->role = 'system';
        $sysMsg->content = Plugin::getInstance()->handoff->t($session, 'User ended the conversation.');
        $sysMsg->save(false);
        return true;
    }

    public function rateChat(string $sessionToken, int $rating): bool
    {
        $session = ChatSessionRecord::findOne(['sessionToken' => $sessionToken]);
        if (!$session) {
            return false;
        }
        $session->chatRating = $rating === 0 ? null : ($rating > 0 ? 1 : -1);
        $session->save(false);
        return true;
    }

    public function purgeOldLogs(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        $cutoff = (new \DateTime())->modify("-{$retentionDays} days");
        $sessions = (new \craft\db\Query())
            ->select(['id'])
            ->from('{{%chatbot_sessions}}')
            ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->column();
        if (empty($sessions)) {
            return 0;
        }
        Craft::$app->db->createCommand()
            ->delete('{{%chatbot_messages}}', ['sessionId' => $sessions])
            ->execute();
        Craft::$app->db->createCommand()
            ->delete('{{%chatbot_sessions}}', ['id' => $sessions])
            ->execute();
        return count($sessions);
    }
}
