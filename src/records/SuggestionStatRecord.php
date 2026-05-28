<?php

namespace cstudiossro\craftcschatbot\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $suggestion
 * @property int $clicks
 * @property string|null $lastClickedAt
 */
class SuggestionStatRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_suggestion_stats}}';
    }
}
