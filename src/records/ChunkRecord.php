<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sourceType
 * @property int $sourceId
 * @property int|null $siteId
 * @property string|null $language
 * @property int $position
 * @property string|null $section
 * @property string $content
 * @property string|null $embedding
 * @property int $tokens
 */
class ChunkRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_chunks}}';
    }
}
