<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $entryId
 * @property int $siteId
 * @property int $sectionId
 * @property string $status
 * @property int $chunkCount
 * @property string|null $errorMessage
 * @property string|null $lastTrainedAt
 */
class TrainingEntryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_training_entries}}';
    }
}
