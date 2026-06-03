<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * One trained item from a custom (plugin-contributed) training source.
 *
 * @property int $id
 * @property string $sourceKey   the source's handle
 * @property int $itemId         the source's own element/record id
 * @property int $siteId
 * @property string|null $title  cached label for the CP list
 * @property string $status
 * @property int $chunkCount
 * @property string|null $errorMessage
 * @property string|null $lastTrainedAt
 */
class TrainingSourceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_training_sources}}';
    }
}
