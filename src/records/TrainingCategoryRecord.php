<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $categoryId
 * @property int $siteId
 * @property int $groupId
 * @property string $status
 * @property int $chunkCount
 * @property string|null $errorMessage
 * @property string|null $lastTrainedAt
 */
class TrainingCategoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_training_categories}}';
    }
}
