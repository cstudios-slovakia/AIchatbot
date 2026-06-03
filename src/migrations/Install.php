<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%chatbot_messages}}');
        $this->dropTableIfExists('{{%chatbot_sessions}}');
        $this->dropTableIfExists('{{%chatbot_chunks}}');
        $this->dropTableIfExists('{{%chatbot_training_entries}}');
        $this->dropTableIfExists('{{%chatbot_training_files}}');
        $this->dropTableIfExists('{{%chatbot_training_urls}}');
        $this->dropTableIfExists('{{%chatbot_training_qa}}');
        $this->dropTableIfExists('{{%chatbot_training_categories}}');
        $this->dropTableIfExists('{{%chatbot_training_globals}}');
        $this->dropTableIfExists('{{%chatbot_suggestion_stats}}');
        $this->dropTableIfExists('{{%chatbot_bans}}');
        return true;
    }

    private function createTables(): void
    {
        $this->createTable('{{%chatbot_training_entries}}', [
            'id' => $this->primaryKey(),
            'entryId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'sectionId' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'chunkCount' => $this->integer()->notNull()->defaultValue(0),
            'errorMessage' => $this->text(),
            'lastTrainedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%chatbot_training_files}}', [
            'id' => $this->primaryKey(),
            'filename' => $this->string(255)->notNull(),
            'originalName' => $this->string(255)->notNull(),
            'size' => $this->integer()->notNull()->defaultValue(0),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'chunkCount' => $this->integer()->notNull()->defaultValue(0),
            'errorMessage' => $this->text(),
            'lastTrainedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%chatbot_training_urls}}', [
            'id' => $this->primaryKey(),
            'url' => $this->string(2048)->notNull(),
            'source' => $this->string(20)->notNull()->defaultValue('manual'),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'chunkCount' => $this->integer()->notNull()->defaultValue(0),
            'errorMessage' => $this->text(),
            'lastTrainedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%chatbot_training_qa}}', [
            'id' => $this->primaryKey(),
            'question' => $this->text()->notNull(),
            'answer' => $this->text()->notNull(),
            'source' => $this->string(20)->notNull()->defaultValue('manual'),
            'active' => $this->boolean()->notNull()->defaultValue(true),
            'sourceMessageId' => $this->integer(),
            'lastTrainedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%chatbot_training_categories}}', [
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

        $this->createTable('{{%chatbot_training_globals}}', [
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

        $this->createTable('{{%chatbot_chunks}}', [
            'id' => $this->primaryKey(),
            'sourceType' => $this->string(20)->notNull(), // entry|file|url|qa
            'sourceId' => $this->integer()->notNull(),
            'position' => $this->integer()->notNull()->defaultValue(0),
            'content' => $this->mediumText()->notNull(),
            'embedding' => $this->longText(),
            'tokens' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%chatbot_sessions}}', [
            'id' => $this->primaryKey(),
            'sessionToken' => $this->string(64)->notNull(),
            'ip' => $this->string(64),
            'userAgent' => $this->string(512),
            'pageUrl' => $this->string(2048),
            'messageCount' => $this->integer()->notNull()->defaultValue(0),
            'ratingPositive' => $this->integer()->notNull()->defaultValue(0),
            'ratingNegative' => $this->integer()->notNull()->defaultValue(0),
            'handoffStatus' => $this->string(20)->notNull()->defaultValue('none'), // none|requested|active|ended
            'handoffAdminId' => $this->integer(),
            'handoffRequestedAt' => $this->dateTime(),
            'handoffStartedAt' => $this->dateTime(),
            'handoffEndedAt' => $this->dateTime(),
            'adminLastReadAt' => $this->dateTime(),
            'userLastReadAt' => $this->dateTime(),
            'lowConfStreak' => $this->integer()->notNull()->defaultValue(0),
            'chatRating' => $this->integer(), // null|1|-1 (overall chat rating from user)
            'chatEndedAt' => $this->dateTime(),
            'contactPromptedAt' => $this->dateTime(),
            'contactCapturedAt' => $this->dateTime(),
            'starred' => $this->boolean()->notNull()->defaultValue(false),
            'adminNotes' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%chatbot_messages}}', [
            'id' => $this->primaryKey(),
            'sessionId' => $this->integer()->notNull(),
            'role' => $this->string(10)->notNull(), // user|bot|admin|system
            'content' => $this->mediumText()->notNull(),
            'confidence' => $this->decimal(5, 4),
            'responseTime' => $this->decimal(8, 3),
            'rating' => $this->integer(), // null|1|-1
            'usedAsQa' => $this->boolean()->notNull()->defaultValue(false),
            'adminId' => $this->integer(),
            'offerHuman' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%chatbot_bans}}', [
            'id' => $this->primaryKey(),
            'ip' => $this->string(64)->notNull(),
            'reason' => $this->string(512),
            'bannedByAdminId' => $this->integer(),
            'expiresAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

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

        $this->createTable('{{%chatbot_suggestion_stats}}', [
            'id' => $this->primaryKey(),
            'suggestion' => $this->string(512)->notNull(),
            'clicks' => $this->integer()->notNull()->defaultValue(0),
            'lastClickedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, '{{%chatbot_training_entries}}', ['entryId', 'siteId'], true);
        $this->createIndex(null, '{{%chatbot_training_entries}}', ['sectionId']);
        $this->createIndex(null, '{{%chatbot_training_entries}}', ['status']);

        $this->createIndex(null, '{{%chatbot_training_files}}', ['filename'], true);
        $this->createIndex(null, '{{%chatbot_training_files}}', ['status']);

        $this->createIndex(null, '{{%chatbot_training_urls}}', ['url(255)']);
        $this->createIndex(null, '{{%chatbot_training_urls}}', ['status']);

        $this->createIndex(null, '{{%chatbot_training_qa}}', ['active']);
        $this->createIndex(null, '{{%chatbot_training_qa}}', ['source']);

        $this->createIndex(null, '{{%chatbot_training_categories}}', ['categoryId', 'siteId'], true);
        $this->createIndex(null, '{{%chatbot_training_categories}}', ['groupId']);
        $this->createIndex(null, '{{%chatbot_training_categories}}', ['status']);

        $this->createIndex(null, '{{%chatbot_training_globals}}', ['globalSetId', 'siteId'], true);
        $this->createIndex(null, '{{%chatbot_training_globals}}', ['status']);

        $this->createIndex(null, '{{%chatbot_chunks}}', ['sourceType', 'sourceId']);

        $this->createIndex(null, '{{%chatbot_contact_requests}}', ['status']);
        $this->createIndex(null, '{{%chatbot_contact_requests}}', ['sessionId']);

        $this->createIndex(null, '{{%chatbot_sessions}}', ['sessionToken'], true);
        $this->createIndex(null, '{{%chatbot_sessions}}', ['dateCreated']);
        $this->createIndex(null, '{{%chatbot_sessions}}', ['handoffStatus']);
        $this->createIndex(null, '{{%chatbot_sessions}}', ['handoffStatus', 'dateUpdated']);
        $this->createIndex(null, '{{%chatbot_sessions}}', ['starred']);

        $this->createIndex(null, '{{%chatbot_messages}}', ['sessionId']);
        $this->createIndex(null, '{{%chatbot_messages}}', ['rating']);
        $this->createIndex(null, '{{%chatbot_messages}}', ['dateCreated']);

        $this->createIndex(null, '{{%chatbot_suggestion_stats}}', ['suggestion(191)'], true);

        $this->createIndex(null, '{{%chatbot_bans}}', ['ip']);
        $this->createIndex(null, '{{%chatbot_bans}}', ['expiresAt']);
    }
}
