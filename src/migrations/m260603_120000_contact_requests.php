<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Adds the contact-request ("missed chats") table plus session columns that
 * track when a contact prompt was shown / captured.
 */
class m260603_120000_contact_requests extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%chatbot_contact_requests}}')) {
            $this->createTable('{{%chatbot_contact_requests}}', [
                'id' => $this->primaryKey(),
                'sessionId' => $this->integer(),
                'name' => $this->string(255),
                'email' => $this->string(255),
                'phone' => $this->string(64),
                'note' => $this->text(),
                'source' => $this->string(30)->notNull()->defaultValue('ai_unanswered'), // ai_unanswered|handoff_timeout|manual
                'status' => $this->string(20)->notNull()->defaultValue('new'), // new|resolved
                'resolvedByAdminId' => $this->integer(),
                'resolvedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%chatbot_contact_requests}}', ['status']);
            $this->createIndex(null, '{{%chatbot_contact_requests}}', ['sessionId']);
        }

        if (!$this->db->columnExists('{{%chatbot_sessions}}', 'contactPromptedAt')) {
            $this->addColumn('{{%chatbot_sessions}}', 'contactPromptedAt', $this->dateTime()->after('chatEndedAt'));
        }
        if (!$this->db->columnExists('{{%chatbot_sessions}}', 'contactCapturedAt')) {
            $this->addColumn('{{%chatbot_sessions}}', 'contactCapturedAt', $this->dateTime()->after('contactPromptedAt'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%chatbot_contact_requests}}');
        if ($this->db->columnExists('{{%chatbot_sessions}}', 'contactPromptedAt')) {
            $this->dropColumn('{{%chatbot_sessions}}', 'contactPromptedAt');
        }
        if ($this->db->columnExists('{{%chatbot_sessions}}', 'contactCapturedAt')) {
            $this->dropColumn('{{%chatbot_sessions}}', 'contactCapturedAt');
        }
        return true;
    }
}
