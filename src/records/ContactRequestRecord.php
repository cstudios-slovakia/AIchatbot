<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * A captured contact ("missed chat") — left by a visitor when the bot could
 * not help and they declined a human, or when no agent claimed a handoff in time.
 *
 * @property int $id
 * @property int|null $sessionId
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $note
 * @property string $source
 * @property string $status
 * @property int|null $resolvedByAdminId
 * @property string|null $resolvedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ContactRequestRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_contact_requests}}';
    }
}
