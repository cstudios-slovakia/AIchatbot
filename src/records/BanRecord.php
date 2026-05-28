<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $ip
 * @property string|null $reason
 * @property int|null $bannedByAdminId
 * @property string|null $expiresAt
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class BanRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_bans}}';
    }
}
