<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use cstudiossro\craftcschatbot\records\BanRecord;
use DateTime;
use DateTimeZone;
use yii\base\Component;

class Bans extends Component
{
    private static function utcTs(string $dbDate): int
    {
        // Craft stores datetimes as UTC strings without TZ. strtotime() would assume local tz.
        try {
            return (new DateTime($dbDate, new DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Parse a duration token into seconds. Accepts: "1h", "24h", "7d", "30d", "2w", "forever", or integer seconds.
     * Returns null for permanent. Returns 0 for invalid/no-ban.
     */
    public static function parseDuration(string $token): ?int
    {
        $t = strtolower(trim($token));
        if ($t === '' || $t === '0') {
            return 0;
        }
        if ($t === 'forever' || $t === 'permanent' || $t === 'perm') {
            return null;
        }
        if (preg_match('/^(\d+)\s*([smhdw])$/', $t, $m)) {
            $n = (int)$m[1];
            return match ($m[2]) {
                's' => $n,
                'm' => $n * 60,
                'h' => $n * 3600,
                'd' => $n * 86400,
                'w' => $n * 604800,
                default => 0,
            };
        }
        if (ctype_digit($t)) {
            return (int)$t;
        }
        return 0;
    }

    public function ban(string $ip, ?int $ttlSeconds, ?string $reason, ?int $adminId): ?BanRecord
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        // Replace existing active ban for same IP
        $existing = BanRecord::find()->where(['ip' => $ip])->all();
        foreach ($existing as $e) {
            $e->delete();
        }
        $rec = new BanRecord();
        $rec->ip = $ip;
        $rec->reason = $reason ? mb_substr($reason, 0, 500) : null;
        $rec->bannedByAdminId = $adminId;
        $rec->expiresAt = $ttlSeconds === null
            ? null
            : Db::prepareDateForDb((new DateTime())->modify("+{$ttlSeconds} seconds"));
        $rec->save(false);
        return $rec;
    }

    public function unban(int $id): bool
    {
        $rec = BanRecord::findOne($id);
        if (!$rec) {
            return false;
        }
        return (bool)$rec->delete();
    }

    public function unbanIp(string $ip): int
    {
        $count = 0;
        foreach (BanRecord::find()->where(['ip' => $ip])->all() as $rec) {
            if ($rec->delete()) {
                $count++;
            }
        }
        return $count;
    }

    public function isBanned(?string $ip): bool
    {
        if (!$ip) {
            return false;
        }
        $row = (new Query())
            ->select(['expiresAt'])
            ->from('{{%chatbot_bans}}')
            ->where(['ip' => $ip])
            ->limit(1)
            ->one();
        if (!$row) {
            return false;
        }
        if ($row['expiresAt'] === null) {
            return true;
        }
        return self::utcTs((string)$row['expiresAt']) > time();
    }

    public function findFor(?string $ip): ?array
    {
        if (!$ip) {
            return null;
        }
        $row = (new Query())
            ->select(['id', 'ip', 'reason', 'expiresAt', 'bannedByAdminId', 'dateCreated'])
            ->from('{{%chatbot_bans}}')
            ->where(['ip' => $ip])
            ->limit(1)
            ->one();
        if (!$row) {
            return null;
        }
        if ($row['expiresAt'] !== null && self::utcTs((string)$row['expiresAt']) <= time()) {
            return null;
        }
        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $rows = (new Query())
            ->select(['id', 'ip', 'reason', 'expiresAt', 'bannedByAdminId', 'dateCreated'])
            ->from('{{%chatbot_bans}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
        $now = time();
        return array_values(array_filter($rows, function ($r) use ($now) {
            return $r['expiresAt'] === null || self::utcTs((string)$r['expiresAt']) > $now;
        }));
    }

    public function purgeExpired(): int
    {
        return Craft::$app->db->createCommand()
            ->delete('{{%chatbot_bans}}', ['and', ['not', ['expiresAt' => null]], ['<', 'expiresAt', Db::prepareDateForDb(new DateTime())]])
            ->execute();
    }
}
