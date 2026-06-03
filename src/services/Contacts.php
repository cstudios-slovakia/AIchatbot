<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use cstudiossro\craftcschatbot\records\ContactRequestRecord;
use DateTime;
use yii\base\Component;

/**
 * Captures visitor contact details ("missed chats") and exposes them to the CP.
 */
class Contacts extends Component
{
    public const SOURCE_AI = 'ai_unanswered';
    public const SOURCE_TIMEOUT = 'handoff_timeout';
    public const SOURCE_MANUAL = 'manual';

    public const STATUS_NEW = 'new';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DELETED = 'deleted';

    /**
     * Store a contact request left by a visitor. Requires at least one of email/phone.
     * Marks the session as captured and drops a system line into the transcript.
     */
    public function capture(
        ?ChatSessionRecord $session,
        ?string $name,
        ?string $email,
        ?string $phone,
        ?string $note,
        string $source = self::SOURCE_AI
    ): ?ContactRequestRecord {
        $email = $email !== null ? trim($email) : null;
        $phone = $phone !== null ? trim($phone) : null;
        $name = $name !== null ? trim($name) : null;
        $note = $note !== null ? trim($note) : null;

        if (($email === null || $email === '') && ($phone === null || $phone === '')) {
            return null; // need a way to reach them
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $rec = new ContactRequestRecord();
        $rec->sessionId = $session?->id ? (int)$session->id : null;
        $rec->name = $name ?: null;
        $rec->email = $email ?: null;
        $rec->phone = $phone ?: null;
        $rec->note = $note ?: null;
        $rec->source = in_array($source, [self::SOURCE_AI, self::SOURCE_TIMEOUT, self::SOURCE_MANUAL], true)
            ? $source
            : self::SOURCE_AI;
        $rec->status = self::STATUS_NEW;
        $rec->save(false);

        if ($session) {
            $session->contactCapturedAt = Db::prepareDateForDb(new DateTime());
            $session->save(false);
            $handoff = Plugin::getInstance()->handoff;
            $handoff->logSystem(
                $session,
                $handoff->t($session, 'Visitor left contact details for follow-up.')
            );
        }
        return $rec;
    }

    public function hasCaptured(ChatSessionRecord $session): bool
    {
        if ($session->contactCapturedAt) {
            return true;
        }
        if (!$session->id) {
            return false;
        }
        return (bool)ContactRequestRecord::find()->where(['sessionId' => (int)$session->id])->exists();
    }

    /**
     * Decide whether the widget should show the contact form because a requested
     * handoff has gone unclaimed past the timeout. Lazily records the prompt and
     * drops a system line the first time it fires.
     */
    public function shouldPromptForContact(ChatSessionRecord $session): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->contactCaptureEnabled) {
            return false;
        }
        $minutes = (int)$settings->contactPromptTimeoutMinutes;
        if ($minutes <= 0) {
            return false;
        }
        if ($session->handoffStatus !== 'requested') {
            return false;
        }
        if ($this->hasCaptured($session)) {
            return false;
        }
        if (!$session->handoffRequestedAt) {
            return false;
        }

        // Already prompted — keep showing the form until they submit.
        if ($session->contactPromptedAt) {
            return true;
        }

        $requestedAt = new DateTime($session->handoffRequestedAt, new \DateTimeZone('UTC'));
        $age = (time() - $requestedAt->getTimestamp()) / 60;
        if ($age < $minutes) {
            return false;
        }

        $session->contactPromptedAt = Db::prepareDateForDb(new DateTime());
        $session->save(false);
        $handoff = Plugin::getInstance()->handoff;
        $handoff->logSystem(
            $session,
            $handoff->t($session, 'No agent is available right now. Leave your email or phone and a real person will follow up.')
        );
        return true;
    }

    public function newCount(): int
    {
        return (int)(new Query())
            ->from('{{%chatbot_contact_requests}}')
            ->where(['status' => self::STATUS_NEW])
            ->count();
    }

    public function resolve(int $id, int $adminId, bool $resolved): bool
    {
        $rec = ContactRequestRecord::findOne($id);
        if (!$rec) {
            return false;
        }
        if ($resolved) {
            $rec->status = self::STATUS_RESOLVED;
            $rec->resolvedByAdminId = $adminId ?: null;
            $rec->resolvedAt = Db::prepareDateForDb(new DateTime());
        } else {
            $rec->status = self::STATUS_NEW;
            $rec->resolvedByAdminId = null;
            $rec->resolvedAt = null;
        }
        $rec->save(false);
        return true;
    }

    /**
     * Soft-delete: move to the "deleted" bucket. Hidden from New/Resolved,
     * still recoverable from the Deleted filter.
     */
    public function softDelete(int $id): bool
    {
        $rec = ContactRequestRecord::findOne($id);
        if (!$rec) {
            return false;
        }
        $rec->status = self::STATUS_DELETED;
        $rec->save(false);
        return true;
    }

    /**
     * Restore a soft-deleted request back to New.
     */
    public function restore(int $id): bool
    {
        $rec = ContactRequestRecord::findOne($id);
        if (!$rec) {
            return false;
        }
        $rec->status = self::STATUS_NEW;
        $rec->resolvedByAdminId = null;
        $rec->resolvedAt = null;
        $rec->save(false);
        return true;
    }

    /**
     * Permanently remove the row from the database.
     */
    public function delete(int $id): bool
    {
        $rec = ContactRequestRecord::findOne($id);
        if (!$rec) {
            return false;
        }
        $rec->delete();
        return true;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listForAdmin(string $status = '', int $page = 1, int $perPage = 25): array
    {
        $query = (new Query())
            ->from(['c' => '{{%chatbot_contact_requests}}']);
        if (in_array($status, [self::STATUS_NEW, self::STATUS_RESOLVED, self::STATUS_DELETED], true)) {
            $query->andWhere(['c.status' => $status]);
        } else {
            // "All" excludes soft-deleted rows — they live under the Deleted filter only.
            $query->andWhere(['!=', 'c.status', self::STATUS_DELETED]);
        }
        $total = (int)(clone $query)->count();

        $rows = $query
            ->select([
                'c.id', 'c.sessionId', 'c.name', 'c.email', 'c.phone', 'c.note',
                'c.source', 'c.status', 'c.resolvedByAdminId', 'c.resolvedAt', 'c.dateCreated',
                'sessionToken' => 's.sessionToken',
            ])
            ->leftJoin(['s' => '{{%chatbot_sessions}}'], 's.id = c.sessionId')
            ->orderBy(['c.status' => SORT_ASC, 'c.id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }
}
