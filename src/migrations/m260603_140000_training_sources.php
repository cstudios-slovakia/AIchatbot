<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Adds the tracking table for custom (plugin-contributed) training sources.
 * Each row is one trained item from a source registered via
 * Sources::EVENT_REGISTER_SOURCES.
 */
class m260603_140000_training_sources extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%chatbot_training_sources}}')) {
            $this->createTable('{{%chatbot_training_sources}}', [
                'id' => $this->primaryKey(),
                'sourceKey' => $this->string(20)->notNull(),
                'itemId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'title' => $this->string(255),
                'status' => $this->string(20)->notNull()->defaultValue('pending'),
                'chunkCount' => $this->integer()->notNull()->defaultValue(0),
                'errorMessage' => $this->text(),
                'lastTrainedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%chatbot_training_sources}}', ['sourceKey', 'itemId', 'siteId'], true);
            $this->createIndex(null, '{{%chatbot_training_sources}}', ['sourceKey']);
            $this->createIndex(null, '{{%chatbot_training_sources}}', ['status']);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%chatbot_training_sources}}');
        return true;
    }
}
