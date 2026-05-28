<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $sessionId
 * @property string $role
 * @property string $content
 * @property float|null $confidence
 * @property float|null $responseTime
 * @property int|null $rating
 * @property bool $usedAsQa
 * @property int|null $adminId
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ChatMessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_messages}}';
    }
}
