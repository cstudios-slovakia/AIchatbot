<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class m260515_160000_session_notes extends Migration
{
    public function safeUp(): bool
    {
        $t = '{{%chatbot_sessions}}';
        if (!$this->columnExists($t, 'adminNotes')) {
            $this->addColumn($t, 'adminNotes', $this->text());
        }
        return true;
    }

    public function safeDown(): bool
    {
        $t = '{{%chatbot_sessions}}';
        if ($this->columnExists($t, 'adminNotes')) {
            $this->dropColumn($t, 'adminNotes');
        }
        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $schema = $this->db->getTableSchema($table, true);
        return $schema && isset($schema->columns[$column]);
    }
}
