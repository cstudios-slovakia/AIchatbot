<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Scopes Q&A pairs, crawled URLs and uploaded files to a site.
 *
 * Only element-backed sources carried a siteId, so everything else was indexed
 * with siteId NULL and therefore matched every site of a multilingual install —
 * a Slovak visitor could be answered from the Hungarian FAQ. NULL keeps meaning
 * "all sites", which is both the sensible default and what existing rows get,
 * so nothing changes for single-site installs.
 *
 * Q&A pairs also gain a translate flag: one authored pair, embedded once per
 * site in that site's language, so retrieval matches how visitors actually ask
 * without anyone maintaining parallel copies.
 */
class m260807_120000_source_site_scope extends Migration
{
    public function safeUp(): bool
    {
        foreach (['{{%chatbot_training_qa}}', '{{%chatbot_training_urls}}', '{{%chatbot_training_files}}'] as $table) {
            if (!$this->db->columnExists($table, 'siteId')) {
                $this->addColumn($table, 'siteId', $this->integer()->null()->after('id'));
                $this->createIndex(null, $table, ['siteId'], false);
            }
        }
        $qa = '{{%chatbot_training_qa}}';
        if (!$this->db->columnExists($qa, 'translate')) {
            $this->addColumn($qa, 'translate', $this->boolean()->notNull()->defaultValue(false)->after('siteId'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        foreach (['{{%chatbot_training_qa}}', '{{%chatbot_training_urls}}', '{{%chatbot_training_files}}'] as $table) {
            if ($this->db->columnExists($table, 'siteId')) {
                $this->dropColumn($table, 'siteId');
            }
        }
        if ($this->db->columnExists('{{%chatbot_training_qa}}', 'translate')) {
            $this->dropColumn('{{%chatbot_training_qa}}', 'translate');
        }
        return true;
    }
}
