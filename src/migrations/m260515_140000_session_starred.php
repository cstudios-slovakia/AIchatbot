<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class m260515_140000_session_starred extends Migration
{
    public function safeUp(): bool
    {
        $t = '{{%chatbot_sessions}}';
        if (!$this->columnExists($t, 'starred')) {
            $this->addColumn($t, 'starred', $this->boolean()->notNull()->defaultValue(false));
            $this->createIndex(null, $t, ['starred']);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $t = '{{%chatbot_sessions}}';
        if ($this->columnExists($t, 'starred')) {
            $this->dropColumn($t, 'starred');
        }
        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $schema = $this->db->getTableSchema($table, true);
        return $schema && isset($schema->columns[$column]);
    }
}
