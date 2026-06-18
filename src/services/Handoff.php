<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatMessageRecord;
use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use DateTime;
use yii\base\Component;

class Handoff extends Component
{
    public const STATUS_NONE = 'none';
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';

    public function request(ChatSessionRecord $session): bool
    {
        if (in_array($session->handoffStatus, [self::STATUS_REQUESTED, self::STATUS_ACTIVE], true)) {
            return true;
        }
        $session->handoffStatus = self::STATUS_REQUESTED;
        $session->handoffRequestedAt = Db::prepareDateForDb(new DateTime());
        $session->handoffAdminId = null;
        $session->handoffStartedAt = null;
        $session->handoffEndedAt = null;
        $session->save(false);

        $this->logSystem($session, $this->t($session, 'User requested to chat with a human.'));
        $this->notifyWaiting($session);
        return true;
    }

    /**
     * Email the configured recipients that a visitor is waiting for a human.
     * Best-effort: failures are logged, never bubbled to the visitor.
     */
    private function notifyWaiting(ChatSessionRecord $session): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->handoffNotifyEnabled) {
            return;
        }
        $to = \craft\helpers\App::parseEnv($settings->handoffNotifyEmail);
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string)$to)), fn($v) => $v !== ''));
        if (!$recipients) {
            return;
        }

        $shortId = sprintf('%05d-%s', (int)$session->id, strtoupper(substr((string)$session->sessionToken, 0, 4)));
        $cpUrl = \craft\helpers\UrlHelper::cpUrl('interactive-ai-assistant/live-chat');
        $lines = [
            'A visitor is waiting to chat with a human.',
            '',
            'Conversation: ' . $shortId,
        ];
        if ($session->pageUrl) {
            $lines[] = 'Page: ' . $session->pageUrl;
        }
        $lines[] = '';
        $lines[] = 'Open Live Chat: ' . $cpUrl;

        try {
            Craft::$app->mailer->compose()
                ->setTo($recipients)
                ->setSubject('New live chat request — ' . $shortId)
                ->setTextBody(implode("\n", $lines))
                ->send();
        } catch (\Throwable $e) {
            Craft::error('Handoff notification failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    public function claim(int $sessionId, int $adminId): ?ChatSessionRecord
    {
        $session = ChatSessionRecord::findOne($sessionId);
        if (!$session) {
            return null;
        }
        if ($session->handoffStatus === self::STATUS_ACTIVE && (int)$session->handoffAdminId !== $adminId) {
            return null;
        }
        $session->handoffStatus = self::STATUS_ACTIVE;
        $session->handoffAdminId = $adminId;
        if (!$session->handoffStartedAt) {
            $session->handoffStartedAt = Db::prepareDateForDb(new DateTime());
        }
        $session->adminLastReadAt = Db::prepareDateForDb(new DateTime());
        $session->save(false);

        $admin = Craft::$app->users->getUserById($adminId);
        $name = $admin ? ($admin->fullName ?: $admin->username) : 'Agent';
        $this->logSystem($session, $this->t($session, '{name} joined the conversation.', ['name' => $name]));
        return $session;
    }

    public function reply(int $sessionId, int $adminId, string $text): ?ChatMessageRecord
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $session = ChatSessionRecord::findOne($sessionId);
        if (!$session || $session->handoffStatus !== self::STATUS_ACTIVE) {
            return null;
        }
        if ((int)$session->handoffAdminId !== $adminId) {
            return null;
        }
        $msg = new ChatMessageRecord();
        $msg->sessionId = (int)$session->id;
        $msg->role = 'admin';
        $msg->content = $text;
        $msg->adminId = $adminId;
        $msg->save(false);

        $session->messageCount = (int)$session->messageCount + 1;
        $session->adminLastReadAt = Db::prepareDateForDb(new DateTime());
        $session->save(false);
        return $msg;
    }

    /**
     * Sweep requested/active handoff sessions where the most recent activity is older than $minutes.
     * Closes them as if the system ended the chat. Returns number closed.
     */
    public function sweepInactive(int $minutes): int
    {
        if ($minutes <= 0) return 0;
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$minutes} minutes"));
        // Find candidate sessions: requested or active, not already ended.
        $rows = (new Query())
            ->select(['id', 'handoffStatus', 'handoffRequestedAt', 'handoffStartedAt', 'adminLastReadAt', 'userLastReadAt', 'dateUpdated'])
            ->from('{{%chatbot_sessions}}')
            ->where(['in', 'handoffStatus', [self::STATUS_REQUESTED, self::STATUS_ACTIVE]])
            ->andWhere(['chatEndedAt' => null])
            ->all();

        $closed = 0;
        foreach ($rows as $r) {
            $sessionId = (int)$r['id'];
            // Last activity = max(last message, last read on either side, started/requested timestamps)
            $lastMsg = (new Query())
                ->select(['MAX(dateCreated)'])
                ->from('{{%chatbot_messages}}')
                ->where(['sessionId' => $sessionId])
                ->scalar();
            $candidates = array_filter([
                $lastMsg ?: null,
                $r['adminLastReadAt'] ?? null,
                $r['userLastReadAt'] ?? null,
                $r['handoffStartedAt'] ?? null,
                $r['handoffRequestedAt'] ?? null,
            ]);
            $last = $candidates ? max($candidates) : null;
            if ($last === null) continue;
            if ($last >= $cutoff) continue;
            if ($this->endByTimeout($sessionId, $minutes)) {
                $closed++;
            }
        }
        return $closed;
    }

    /**
     * Closes a session with a system message indicating inactivity. No adminId required.
     */
    public function endByTimeout(int $sessionId, int $minutes): bool
    {
        $session = ChatSessionRecord::findOne($sessionId);
        if (!$session) return false;
        if ($session->chatEndedAt) return false;
        $now = Db::prepareDateForDb(new DateTime());
        $session->handoffStatus = self::STATUS_ENDED;
        $session->handoffEndedAt = $now;
        $session->chatEndedAt = $now;
        $session->lowConfStreak = 0;
        $session->save(false);
        $this->logSystem($session, $this->t($session, 'Conversation auto-closed after {minutes} minutes of inactivity.', ['minutes' => $minutes]));
        return true;
    }

    public function end(int $sessionId, int $adminId): bool
    {
        $session = ChatSessionRecord::findOne($sessionId);
        if (!$session) {
            return false;
        }
        $now = Db::prepareDateForDb(new DateTime());
        $session->handoffStatus = self::STATUS_ENDED;
        $session->handoffEndedAt = $now;
        $session->chatEndedAt = $now;
        $session->lowConfStreak = 0;
        $session->save(false);

        $admin = Craft::$app->users->getUserById($adminId);
        $name = Plugin::getInstance()->getSettings()->showAdminName && $admin
            ? ($admin->fullName ?: $admin->username)
            : 'The agent';
        $this->logSystem($session, $this->t($session, '{name} ended the conversation.', ['name' => $name]));
        return true;
    }

    public function toggleStar(int $sessionId): ?bool
    {
        $session = ChatSessionRecord::findOne($sessionId);
        if (!$session) return null;
        $session->starred = !$session->starred;
        $session->save(false);
        return (bool)$session->starred;
    }

    public function markAdminRead(int $sessionId, int $adminId): void
    {
        $session = ChatSessionRecord::findOne($sessionId);
        if (!$session) {
            return;
        }
        if ($session->handoffAdminId && (int)$session->handoffAdminId !== $adminId) {
            return;
        }
        $session->adminLastReadAt = Db::prepareDateForDb(new DateTime());
        $session->save(false);
    }

    public function markUserRead(ChatSessionRecord $session): void
    {
        $session->userLastReadAt = Db::prepareDateForDb(new DateTime());
        $session->save(false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messagesAfter(int $sessionId, int $afterId): array
    {
        return (new Query())
            ->select(['id', 'role', 'content', 'adminId', 'rating', 'offerHuman', 'dateCreated'])
            ->from('{{%chatbot_messages}}')
            ->where(['sessionId' => $sessionId])
            ->andWhere(['>', 'id', $afterId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    /**
     * @return array{waiting:array, active:array}
     */
    public function listForAdmin(): array
    {
        $waiting = (new Query())
            ->select([
                's.id', 's.sessionToken', 's.pageUrl', 's.handoffStatus',
                's.handoffRequestedAt', 's.handoffStartedAt', 's.handoffAdminId',
                's.adminLastReadAt', 's.messageCount', 's.starred', 's.dateCreated',
            ])
            ->from(['s' => '{{%chatbot_sessions}}'])
            ->where(['s.handoffStatus' => self::STATUS_REQUESTED])
            ->orderBy(['s.starred' => SORT_DESC, 's.handoffRequestedAt' => SORT_ASC])
            ->all();

        $active = (new Query())
            ->select([
                's.id', 's.sessionToken', 's.pageUrl', 's.handoffStatus',
                's.handoffRequestedAt', 's.handoffStartedAt', 's.handoffAdminId',
                's.adminLastReadAt', 's.messageCount', 's.starred', 's.dateUpdated',
            ])
            ->from(['s' => '{{%chatbot_sessions}}'])
            ->where(['s.handoffStatus' => self::STATUS_ACTIVE])
            ->orderBy(['s.starred' => SORT_DESC, 's.handoffStartedAt' => SORT_DESC, 's.id' => SORT_DESC])
            ->all();

        foreach ([&$waiting, &$active] as &$rows) {
            foreach ($rows as &$r) {
                $r['lastMessage'] = $this->lastUserOrSystemPreview((int)$r['id']);
                $r['unreadCount'] = $this->unreadInSession((int)$r['id'], $r['adminLastReadAt'] ?? null);
            }
        }
        return ['waiting' => $waiting, 'active' => $active];
    }

    public function waitingCount(): int
    {
        return (int)(new Query())
            ->from('{{%chatbot_sessions}}')
            ->where(['handoffStatus' => self::STATUS_REQUESTED])
            ->count();
    }

    public function activeForAdmin(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        return (int)(new Query())
            ->from('{{%chatbot_sessions}}')
            ->where([
                'handoffStatus' => self::STATUS_ACTIVE,
                'handoffAdminId' => $userId,
                'chatEndedAt' => null,
            ])
            ->count();
    }

    /**
     * Total nav badge count: pending claims + active claimed by user + unread.
     * Unread implies active so it's roughly: waiting + max(active, unread).
     */
    public function badgeCount(int $userId): array
    {
        $waiting = $this->waitingCount();
        $active = $this->activeForAdmin($userId);
        $unread = $this->unreadForAdmin($userId);
        // total = waiting + active (unread is subset of active)
        return [
            'waiting' => $waiting,
            'active' => $active,
            'unread' => $unread,
            'total' => $waiting + $active,
        ];
    }

    /**
     * Compact per-session unread counts for waiting + active sessions, used by the global
     * CP-nav poll to play a distinct notification tone per chat on any admin page.
     * @return array<int, array{id:int, unread:int}>
     */
    public function unreadBySessionForBadge(): array
    {
        $rows = (new Query())
            ->select(['s.id AS id', 'COUNT(m.id) AS unread'])
            ->from(['s' => '{{%chatbot_sessions}}'])
            ->leftJoin(['m' => '{{%chatbot_messages}}'], [
                'and',
                'm.sessionId = s.id',
                ['m.role' => 'user'],
                ['or', ['s.adminLastReadAt' => null], ['>', 'm.dateCreated', new \yii\db\Expression('s.adminLastReadAt')]],
            ])
            ->where(['s.handoffStatus' => [self::STATUS_REQUESTED, self::STATUS_ACTIVE]])
            ->groupBy(['s.id'])
            ->all();
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'unread' => (int)$r['unread']], $rows);
    }

    public function unreadForAdmin(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        return (int)(new Query())
            ->from(['m' => '{{%chatbot_messages}}'])
            ->innerJoin(['s' => '{{%chatbot_sessions}}'], 's.id = m.sessionId')
            ->where([
                's.handoffStatus' => self::STATUS_ACTIVE,
                's.handoffAdminId' => $userId,
                'm.role' => 'user',
            ])
            ->andWhere([
                'or',
                ['s.adminLastReadAt' => null],
                ['>', 'm.dateCreated', new \yii\db\Expression('s.adminLastReadAt')],
            ])
            ->count();
    }

    private function unreadInSession(int $sessionId, ?string $adminLastReadAt): int
    {
        $q = (new Query())
            ->from('{{%chatbot_messages}}')
            ->where(['sessionId' => $sessionId, 'role' => 'user']);
        if ($adminLastReadAt) {
            $q->andWhere(['>', 'dateCreated', $adminLastReadAt]);
        }
        return (int)$q->count();
    }

    private function lastUserOrSystemPreview(int $sessionId): ?string
    {
        $row = (new Query())
            ->select(['content'])
            ->from('{{%chatbot_messages}}')
            ->where(['sessionId' => $sessionId])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->one();
        if (!$row) {
            return null;
        }
        $text = (string)$row['content'];
        return mb_substr($text, 0, 80);
    }

    /**
     * Translate a system-message string into the visitor's site language so
     * transcripts read correctly on the widget (and in the CP).
     *
     * @param array<string, mixed> $params
     */
    public function t(ChatSessionRecord $session, string $message, array $params = []): string
    {
        $language = \cstudiossro\craftcschatbot\models\Settings::resolveSiteFromUrl($session->pageUrl)?->language;
        return Craft::t('interactive-ai-assistant', $message, $params, $language);
    }

    public function logSystem(ChatSessionRecord $session, string $text): ChatMessageRecord
    {
        $msg = new ChatMessageRecord();
        $msg->sessionId = (int)$session->id;
        $msg->role = 'system';
        $msg->content = $text;
        $msg->save(false);
        return $msg;
    }
}
