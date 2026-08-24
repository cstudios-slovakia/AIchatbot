<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

/**
 * Marks form submissions as seen by an admin. `status` on that table is the
 * delivery state (pending/sent/failed), which says nothing about whether a
 * human ever looked at the lead — so a delivered submission had no way of
 * showing up as new anywhere in the CP.
 *
 * Existing rows are backfilled as read: they predate the notification and
 * flagging them all as new on upgrade would be noise, not a signal.
 */
class m260807_150000_form_submission_read extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%chatbot_form_submissions}}';
        if ($this->db->tableExists($table) && !$this->db->columnExists($table, 'readAt')) {
            $this->addColumn($table, 'readAt', $this->dateTime()->after('deliveryLog'));
            $this->createIndex(null, $table, ['readAt']);
            $this->update($table, ['readAt' => (new \DateTime())->format('Y-m-d H:i:s')], ['readAt' => null]);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%chatbot_form_submissions}}';
        if ($this->db->tableExists($table) && $this->db->columnExists($table, 'readAt')) {
            $this->dropColumn($table, 'readAt');
        }
        return true;
    }
}
