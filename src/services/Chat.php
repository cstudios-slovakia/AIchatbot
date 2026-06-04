<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use cstudiossro\craftcschatbot\events\BuildSystemPromptEvent;
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

        // embed question
        $qVecArr = $plugin->openAi->embed([$question]);
        $qVec = $qVecArr[0] ?? [];

        $hits = $plugin->vectorSearch->topK($qVec, $settings->maxContextChunks, 0.0);

        $usableHits = array_filter($hits, fn($h) => $h['score'] >= $settings->minSimilarityScore);
        $confidence = !empty($hits) ? (float)$hits[0]['score'] : 0.0;

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

        if ($context !== '') {
            $systemPrompt .= "\n\n# Context\n" . $context;
        } else {
            $systemPrompt .= "\n\n# Context\n(none)";
        }

        $history = $this->recentHistory($session->id, 6);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'] === 'bot' ? 'assistant' : 'user', 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $tools = !empty($enabledCaps) ? $plugin->capabilities->toolSchemas($enabledCaps) : [];
        $reply = $this->complete($messages, $tools);
        $responseTime = round(microtime(true) - $start, 3);

        // Sentinel token from the model = language-agnostic "offer human" signal. Strip before showing.
        $hasHandoffToken = str_contains($reply, '[[HANDOFF_OFFER]]');
        if ($hasHandoffToken) {
            $reply = trim(preg_replace('/\s*\[\[HANDOFF_OFFER\]\]\s*/u', '', $reply) ?? $reply);
        }

        $botMsg = $this->logMessage($session, 'bot', $reply, $confidence, $responseTime);

        // low-confidence streak tracking → offer help (human handoff and/or contact capture)
        $offerHuman = false;
        if ($confidence < $settings->minSimilarityScore) {
            $session->lowConfStreak = (int)$session->lowConfStreak + 1;
            if ($session->lowConfStreak >= 2) {
                $offerHuman = true;
            }
        } else {
            $session->lowConfStreak = 0;
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

        return [
            'reply' => $reply,
            'confidence' => $confidence,
            'responseTime' => $responseTime,
            'messageId' => (int)$botMsg->id,
            'sessionToken' => $session->sessionToken,
            'sessionId' => (int)$session->id,
            'shortId' => sprintf('%05d-%s', (int)$session->id, strtoupper(substr((string)$session->sessionToken, 0, 4))),
            'offerHuman' => $offerHuman,
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
