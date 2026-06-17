<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * A completed conversational form, captured when the assistant calls a form
 * tool with all required fields. Always stored (audit + retry); webhook/email
 * delivery is attempted asynchronously by {@see \cstudiossro\craftcschatbot\jobs\SendFormJob}.
 *
 * @property int $id
 * @property int|null $sessionId
 * @property string $formName
 * @property string $payload      JSON-encoded field => value map
 * @property string $status       pending|sent|failed
 * @property string|null $deliveryLog
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class FormSubmissionRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%chatbot_form_submissions}}';
    }
}
