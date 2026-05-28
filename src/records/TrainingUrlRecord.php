<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $url
 * @property string $source
 * @property string $status
 * @property int $chunkCount
 * @property string|null $errorMessage
 * @property string|null $lastTrainedAt
 */
class TrainingUrlRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_training_urls}}';
    }
}
