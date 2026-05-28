<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class m260514_130000_categories_globals extends Migration
{
    public function safeUp(): bool
    {
        $cat = '{{%chatbot_training_categories}}';
        $glob = '{{%chatbot_training_globals}}';

        if (!$this->db->getTableSchema($cat, true)) {
            $this->createTable($cat, [
                'id' => $this->primaryKey(),
                'categoryId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'groupId' => $this->integer()->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue('pending'),
                'chunkCount' => $this->integer()->notNull()->defaultValue(0),
                'errorMessage' => $this->text(),
                'lastTrainedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, $cat, ['categoryId', 'siteId'], true);
            $this->createIndex(null, $cat, ['groupId']);
            $this->createIndex(null, $cat, ['status']);
        }

        if (!$this->db->getTableSchema($glob, true)) {
            $this->createTable($glob, [
                'id' => $this->primaryKey(),
                'globalSetId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue('pending'),
                'chunkCount' => $this->integer()->notNull()->defaultValue(0),
                'errorMessage' => $this->text(),
                'lastTrainedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, $glob, ['globalSetId', 'siteId'], true);
            $this->createIndex(null, $glob, ['status']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%chatbot_training_categories}}');
        $this->dropTableIfExists('{{%chatbot_training_globals}}');
        return true;
    }
}
