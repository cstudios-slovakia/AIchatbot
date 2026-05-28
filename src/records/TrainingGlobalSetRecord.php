<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $globalSetId
 * @property int $siteId
 * @property string $status
 * @property int $chunkCount
 * @property string|null $errorMessage
 * @property string|null $lastTrainedAt
 */
class TrainingGlobalSetRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_training_globals}}';
    }
}
