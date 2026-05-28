<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sessionToken
 * @property string|null $ip
 * @property string|null $userAgent
 * @property string|null $pageUrl
 * @property int $messageCount
 * @property int $ratingPositive
 * @property int $ratingNegative
 * @property string $handoffStatus
 * @property int|null $handoffAdminId
 * @property string|null $handoffRequestedAt
 * @property string|null $handoffStartedAt
 * @property string|null $handoffEndedAt
 * @property string|null $adminLastReadAt
 * @property string|null $userLastReadAt
 * @property int $lowConfStreak
 * @property int|null $chatRating
 * @property string|null $chatEndedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ChatSessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_sessions}}';
    }
}
