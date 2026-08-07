<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Stores embeddings as packed 32-bit floats instead of JSON text.
 *
 * A 1536-dimension vector is about 30 KB as JSON and 6 KB packed, and every
 * query decoded all of them: at a few thousand chunks that is more memory than
 * a typical PHP process is allowed, which put a hard ceiling on how much
 * content a site could be trained on. Unpacking binary is also far cheaper
 * than parsing JSON.
 *
 * The old column stays so search keeps working on chunks indexed before this;
 * retraining converts them, and the index health report counts what is left.
 */
class m260807_140000_packed_embeddings extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%chatbot_chunks}}';
        if (!$this->db->columnExists($table, 'embeddingBlob')) {
            $this->addColumn($table, 'embeddingBlob', $this->binary()->null()->after('embedding'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%chatbot_chunks}}';
        if ($this->db->columnExists($table, 'embeddingBlob')) {
            $this->dropColumn($table, 'embeddingBlob');
        }
        return true;
    }
}
