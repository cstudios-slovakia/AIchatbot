<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class m260515_120000_bans extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%chatbot_bans}}';
        if (!$this->db->getTableSchema($table, true)) {
            $this->createTable($table, [
                'id' => $this->primaryKey(),
                'ip' => $this->string(64)->notNull(),
                'reason' => $this->string(512),
                'bannedByAdminId' => $this->integer(),
                'expiresAt' => $this->dateTime(), // null = permanent
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, $table, ['ip']);
            $this->createIndex(null, $table, ['expiresAt']);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%chatbot_bans}}');
        return true;
    }
}
