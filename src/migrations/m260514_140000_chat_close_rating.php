<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class m260514_140000_chat_close_rating extends Migration
{
    public function safeUp(): bool
    {
        $sessions = '{{%chatbot_sessions}}';
        if (!$this->columnExists($sessions, 'chatRating')) {
            $this->addColumn($sessions, 'chatRating', $this->integer()); // null|-1|1
        }
        if (!$this->columnExists($sessions, 'chatEndedAt')) {
            $this->addColumn($sessions, 'chatEndedAt', $this->dateTime());
        }
        return true;
    }

    public function safeDown(): bool
    {
        $sessions = '{{%chatbot_sessions}}';
        foreach (['chatRating', 'chatEndedAt'] as $c) {
            if ($this->columnExists($sessions, $c)) {
                $this->dropColumn($sessions, $c);
            }
        }
        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $schema = $this->db->getTableSchema($table, true);
        return $schema && isset($schema->columns[$column]);
    }
}
