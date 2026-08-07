<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use cstudiossro\craftcschatbot\Plugin;
use yii\base\Component;

/**
 * Spam / gibberish filter. Runs server-side as the authoritative wall.
 * Frontend mirrors a subset for UX, but cannot be trusted.
 */
class Filter extends Component
{
    public const REASON_TOO_SHORT = 'too_short';
    public const REASON_TOO_LONG = 'too_long';
    public const REASON_GIBBERISH = 'gibberish';
    public const REASON_RATE_LIMIT = 'rate_limit';
    public const REASON_BLOCKED_WORD = 'blocked_word';

    /**
     * @return array{ok:bool, reason?:string, message?:string}
     */
    public function check(string $text, ?string $sessionToken = null, ?string $ip = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->filterEnabled) {
            return ['ok' => true];
        }

        $raw = trim($text);
        $len = mb_strlen($raw);

        if ($len < (int)$settings->filterMinLength) {
            return ['ok' => false, 'reason' => self::REASON_TOO_SHORT, 'message' => 'Message is too short.'];
        }
        if ($len > (int)$settings->filterMaxLength) {
            return ['ok' => false, 'reason' => self::REASON_TOO_LONG, 'message' => 'Message is too long.'];
        }

        $blocked = array_map('strtolower', array_filter(array_map('trim', $settings->filterBlockedWords ?? [])));
        if ($blocked) {
            $lower = mb_strtolower($raw);
            foreach ($blocked as $w) {
                if ($w !== '' && str_contains($lower, $w)) {
                    return ['ok' => false, 'reason' => self::REASON_BLOCKED_WORD, 'message' => 'Message contains disallowed content.'];
                }
            }
        }

        if (self::looksLikeGibberish($raw)) {
            return ['ok' => false, 'reason' => self::REASON_GIBBERISH, 'message' => 'Please send a real question or sentence.'];
        }

        if ($sessionToken || $ip) {
            $window = (int)$settings->filterRateWindowSeconds;
            $limit = (int)$settings->filterRateMaxMessages;
            if ($window > 0 && $limit > 0 && $this->rateLimited($sessionToken, $ip, $window, $limit)) {
                return ['ok' => false, 'reason' => self::REASON_RATE_LIMIT, 'message' => 'You\'re sending messages too quickly. Slow down a bit.'];
            }
        }

        return ['ok' => true];
    }

    /**
     * Heuristic: looks like random keysmashing.
     * Returns true if message is likely gibberish.
     * Safe for CJK / Arabic / Cyrillic (returns false for non-Latin scripts).
     */
    public static function looksLikeGibberish(string $text): bool
    {
        $text = trim($text);
        $len = mb_strlen($text);
        if ($len === 0) return true;
        if ($len < 4) return false; // too short to judge

        // Skip non-Latin scripts (CJK, Arabic, Hebrew, Cyrillic, etc.)
        if (!preg_match('/^[\p{Latin}\s\p{P}\p{N}]+$/u', $text)) {
            return false;
        }

        // Product codes, SKUs, model numbers, dimensions and acronyms break every
        // heuristic below — they are deliberately vowel-poor. Visitors ask about
        // them constantly ("DSC 6370 SMALL?", "RC2 vs RC3", "90x197 L"), so judge
        // the prose around them and never the codes themselves.
        if (!preg_match('/[\p{L}\p{N}]/u', $text)) {
            return true; // pure punctuation
        }

        $prose = self::stripCodeTokens($text);
        $letters = preg_replace('/[^\p{L}]+/u', '', $prose) ?? '';
        $letterCount = mb_strlen($letters);
        if ($letterCount === 0) {
            return false; // nothing but codes and numbers — a lookup, not a keysmash
        }

        $lower = mb_strtolower($letters);

        // 1. Vowel ratio. Latin words rarely have < 15% vowels (excluding very short).
        if ($letterCount >= 6) {
            $vowels = preg_match_all('/[aeiouy]/i', $lower);
            $ratio = $vowels / $letterCount;
            if ($ratio < 0.15) return true;
            if ($ratio > 0.85) return true; // "aaaeeei"
        }

        // 2. Long consonant run
        if (preg_match('/[bcdfghjklmnpqrstvwxz]{7,}/i', $lower)) {
            return true;
        }

        // 3. Long identical-char run
        if (preg_match('/(.)\1{5,}/u', $lower)) {
            return true;
        }

        // 4. Unique-character entropy: many chars but few distinct
        if ($letterCount >= 12) {
            $unique = count(array_unique(preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: []));
            if ($unique <= 3) return true;
            if (($unique / $letterCount) < 0.2) return true;
        }

        // 5. Long single token (no whitespace at all in a long message)
        if ($len >= 40 && !preg_match('/\s/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Drop the tokens of a message that are identifiers rather than words, so
     * only prose is left for the keysmash heuristics to judge.
     *
     * Removed: anything containing a digit (SKUs, model numbers, dimensions,
     * years) and short all-caps runs (RC2, MDF, NBU, ISO, EI45).
     */
    private static function stripCodeTokens(string $text): string
    {
        $tokens = preg_split('/\s+/u', $text) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            $bare = preg_replace('/^\p{P}+|\p{P}+$/u', '', $token) ?? $token;
            if ($bare === '') {
                continue;
            }
            if (preg_match('/\p{N}/u', $bare)) {
                continue;
            }
            if (mb_strlen($bare) <= 5 && $bare === mb_strtoupper($bare, 'UTF-8')) {
                continue;
            }
            $kept[] = $bare;
        }
        return implode(' ', $kept);
    }

    private function rateLimited(?string $sessionToken, ?string $ip, int $windowSec, int $limit): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - $windowSec);

        if ($sessionToken) {
            $count = (int)(new Query())
                ->from('{{%chatbot_messages}} m')
                ->innerJoin('{{%chatbot_sessions}} s', 's.id = m.sessionId')
                ->where(['s.sessionToken' => $sessionToken, 'm.role' => 'user'])
                ->andWhere(['>=', 'm.dateCreated', $since])
                ->count();
            if ($count >= $limit) return true;
        }

        if ($ip) {
            $count = (int)(new Query())
                ->from('{{%chatbot_messages}} m')
                ->innerJoin('{{%chatbot_sessions}} s', 's.id = m.sessionId')
                ->where(['s.ip' => $ip, 'm.role' => 'user'])
                ->andWhere(['>=', 'm.dateCreated', $since])
                ->count();
            if ($count >= $limit * 3) return true; // ip cap looser than per-session
        }

        return false;
    }
}
