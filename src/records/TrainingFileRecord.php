<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $filename
 * @property string $originalName
 * @property int $size
 * @property string $status
 * @property int $chunkCount
 * @property string|null $errorMessage
 * @property string|null $lastTrainedAt
 */
class TrainingFileRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_training_files}}';
    }
}
