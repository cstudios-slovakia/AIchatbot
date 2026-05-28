<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class m260520_120000_message_offer_human extends Migration
{
    public function safeUp(): bool
    {
        $t = '{{%chatbot_messages}}';
        if (!$this->columnExists($t, 'offerHuman')) {
            $this->addColumn($t, 'offerHuman', $this->boolean()->notNull()->defaultValue(false));
        }
        return true;
    }

    public function safeDown(): bool
    {
        $t = '{{%chatbot_messages}}';
        if ($this->columnExists($t, 'offerHuman')) {
            $this->dropColumn($t, 'offerHuman');
        }
        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $schema = $this->db->getTableSchema($table, true);
        return $schema && isset($schema->columns[$column]);
    }
}
