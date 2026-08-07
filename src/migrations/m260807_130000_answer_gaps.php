<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Records what retrieval actually found for each answered turn, so the
 * questions the assistant handled badly can be listed and fixed.
 *
 * A transcript alone cannot tell you this: a confident-sounding answer built
 * from nothing looks exactly like a good one until someone reads it. Storing
 * the query that was searched for and how many chunks cleared the threshold
 * turns "the training is weak" into a list of specific questions.
 */
class m260807_130000_answer_gaps extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%chatbot_messages}}';
        if (!$this->db->columnExists($table, 'retrievalQuery')) {
            $this->addColumn($table, 'retrievalQuery', $this->string(500)->null()->after('content'));
        }
        // null = retrieval was skipped for this turn (greeting, chit-chat,
        // out-of-scope); 0 = it ran and nothing cleared the threshold.
        if (!$this->db->columnExists($table, 'contextChunks')) {
            $this->addColumn($table, 'contextChunks', $this->integer()->null()->after('retrievalQuery'));
        }
        if (!$this->db->columnExists($table, 'gapResolvedAt')) {
            $this->addColumn($table, 'gapResolvedAt', $this->dateTime()->null()->after('contextChunks'));
        }
        $this->createIndex(null, $table, ['role', 'gapResolvedAt'], false);
        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%chatbot_messages}}';
        foreach (['retrievalQuery', 'contextChunks', 'gapResolvedAt'] as $column) {
            if ($this->db->columnExists($table, $column)) {
                $this->dropColumn($table, $column);
            }
        }
        return true;
    }
}
