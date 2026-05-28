<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $question
 * @property string $answer
 * @property string $source
 * @property bool $active
 * @property int|null $sourceMessageId
 * @property string|null $lastTrainedAt
 */
class TrainingQaRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_training_qa}}';
    }
}
