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
        $session = new ChatSessionRecord();
        $session->sessionToken = StringHelper::randomString(48);
        // IP always stored — needed for bans even if logging disabled
        $session->ip = Craft::$app->getRequest()->getUserIP();
        if ($settings->loggingEnabled) {
            $ua = Craft::$app->getRequest()->getUserAgent();
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
        $settings = Plugin::getInstance()->getSettings();
        $plugin = Plugin::getInstance();
        $session = $this->getOrCreateSession($sessionToken, $pageUrl);

        // Throttle bot mode: if previous message in this session is a user message without a bot reply yet,
        // reject this incoming one. Skip the check for handoff sessions where humans are slow on purpose.
        if (!in_array($session->handoffStatus, ['requested', 'active'], true)) {
            $lastRole = (new \craft\db\Query())
                ->select(['role'])
                ->from('{{%chatbot_messages}}')
                ->where(['sessionId' => $session->id])
                ->andWhere(['in', 'role', ['user', 'bot']])
                ->orderBy(['id' => SORT_DESC])
                ->limit(1)
                ->scalar();
            if ($lastRole === 'user') {
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

        $start = microtime(true);

        // Conversation so far (excludes the user message we just logged). Reused
        // for both building the retrieval query and generation further down.
        $history = $this->recentHistory($session->id, 6);

        // Turn the (possibly elliptical) latest message into a standalone
        // retrieval query and decide whether this turn needs retrieval at all.
        [$retrievalQuery, $needsRetrieval] = $this->buildRetrievalQuery($history, $question);
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
            $candidates = $plugin->vectorSearch->topK($qVec, $pool, 0.0, $retrievalQuery, $needVectors);
            $hits = $this->rerank($candidates, $retrievalQuery);

            $usableHits = array_filter($hits, fn($h) => $h['score'] >= $settings->minSimilarityScore);
            $confidence = !empty($hits) ? (float)$hits[0]['score'] : 0.0;
        }

        $contextBlocks = [];
        foreach ($usableHits as $i => $h) {
            $url = $this->resolveSourceUrl($h['sourceType'], (int)$h['sourceId']);
            $head = $url
                ? sprintf("[%d] (%s) URL: %s", $i + 1, $h['sourceType'], $url)
                : sprintf("[%d] (%s)", $i + 1, $h['sourceType']);
            $contextBlocks[] = $head . "\n" . $h['content'];
        }
        $context = implode("\n\n---\n\n", $contextBlocks);

        $site = \cstudiossro\craftcschatbot\models\Settings::resolveSiteFromUrl($session->pageUrl);
        $siteUid = $site->uid ?? null;
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

        $systemPrompt .= "\n\n# Output format\nFormat all replies as GitHub-flavored Markdown. Use **bold**, *italic*, `inline code`, fenced code blocks with language tags, bullet/numbered lists, headings (## or ###), and [links](https://example.com) where they aid clarity. Do not wrap the whole answer in a code block. Keep formatting purposeful — short answers stay short.\n\nWhen you suggest a page from the site for the user to read, put the link on its own line as `[Title](url)`, using the exact `URL:` value given for the relevant context block below. Never invent, guess, or use placeholder URLs — only link to a page when its real URL is present in the context. The UI renders such standalone links as a rich preview card with the page's title, description and image — so place at most one per paragraph.";
        if ($settings->humanHandoffEnabled) {
            $systemPrompt .= "\n\n# Handoff signal\nWhenever you (a) cannot answer the user's question from the provided context, (b) are uncertain, or (c) the user is explicitly asking for a human, append the exact literal token `[[HANDOFF_OFFER]]` on its own line at the very end of your reply. Do not translate, modify, paraphrase, or comment on this token. Do not output it in any other situation. The UI strips the token and uses it to show a 'Talk to a human' button — language does not matter.";
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
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'] === 'bot' ? 'assistant' : 'user', 'content' => $h['content']];
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
        }
        $rec->save(false);
        return $rec;
    }

    /**
     * @return array<int, array{role:string, content:string}>
     */
    private function recentHistory(int $sessionId, int $limit): array
    {
        $rows = (new \craft\db\Query())
            ->select(['role', 'content'])
            ->from('{{%chatbot_messages}}')
            ->where(['sessionId' => $sessionId])
            ->andWhere(['in', 'role', ['user', 'bot']])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all();
        // skip the most recent (the user msg we just inserted)
        array_shift($rows);
        return array_reverse($rows);
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
     * @return array{0:string, 1:bool} [standaloneQuery, needsRetrieval]
     */
    private function buildRetrievalQuery(array $history, string $question): array
    {
        $settings = Plugin::getInstance()->getSettings();

        // Cheap heuristic guard for pure smalltalk (works without any LLM call).
        $smalltalk = $settings->retrievalGuardEnabled && $this->looksLikeSmalltalk($question);

        // First turn or rewrite disabled: nothing to resolve against.
        if (empty($history) || !$settings->queryRewriteEnabled) {
            return [$question, !$smalltalk];
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
                . "a knowledge-base lookup at all — greetings, thanks and pure chit-chat do not. "
                . 'Respond with ONLY compact JSON: {"query": string, "needs_retrieval": boolean}.';
            $usr = "Conversation so far:\n" . trim($convo) . "\n\nLatest user message: " . $question;
            $msg = Plugin::getInstance()->openAi->chatRaw(
                [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $usr],
                ],
                ['model' => 'gpt-4o-mini', 'temperature' => 0.0]
            );
            $data = json_decode($this->stripJsonFence((string)($msg['content'] ?? '')), true);
            if (is_array($data)) {
                $q = trim((string)($data['query'] ?? ''));
                $q = $q !== '' ? $q : $question;
                $needs = !array_key_exists('needs_retrieval', $data) || (bool)$data['needs_retrieval'];
                if ($settings->retrievalGuardEnabled) {
                    return [$q, $needs];
                }
                return [$q, true];
            }
        } catch (\Throwable) {
            // fall through to the safe default
        }
        return [$question, !$smalltalk];
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
                ['model' => 'gpt-4o-mini', 'temperature' => 0.0]
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
