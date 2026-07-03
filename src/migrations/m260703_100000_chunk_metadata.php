<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Adds retrieval metadata to chunks so search can filter by site/language and
 * so each chunk records the section it came from. Enables per-site retrieval
 * (a query on the Slovak site no longer pulls English chunks) and richer
 * context/citations. Existing rows get NULLs; a full retrain repopulates them.
 */
class m260703_100000_chunk_metadata extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%chatbot_chunks}}';
        if (!$this->db->columnExists($table, 'siteId')) {
            $this->addColumn($table, 'siteId', $this->integer()->null()->after('sourceId'));
        }
        if (!$this->db->columnExists($table, 'language')) {
            $this->addColumn($table, 'language', $this->string(20)->null()->after('siteId'));
        }
        if (!$this->db->columnExists($table, 'section')) {
            $this->addColumn($table, 'section', $this->string(500)->null()->after('position'));
        }
        $this->createIndex(null, $table, ['siteId'], false);
        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%chatbot_chunks}}';
        foreach (['siteId', 'language', 'section'] as $col) {
            if ($this->db->columnExists($table, $col)) {
                $this->dropColumn($table, $col);
            }
        }
        return true;
    }
}
