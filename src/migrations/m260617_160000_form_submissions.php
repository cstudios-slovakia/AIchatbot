<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Adds the conversational-form submissions table — completed forms the
 * assistant collected, plus their delivery status.
 */
class m260617_160000_form_submissions extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%chatbot_form_submissions}}')) {
            $this->createTable('{{%chatbot_form_submissions}}', [
                'id' => $this->primaryKey(),
                'sessionId' => $this->integer(),
                'formName' => $this->string(64)->notNull(),
                'payload' => $this->text(),
                'status' => $this->string(20)->notNull()->defaultValue('pending'), // pending|sent|failed
                'deliveryLog' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%chatbot_form_submissions}}', ['status']);
            $this->createIndex(null, '{{%chatbot_form_submissions}}', ['formName']);
            $this->createIndex(null, '{{%chatbot_form_submissions}}', ['sessionId']);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%chatbot_form_submissions}}');
        return true;
    }
}
