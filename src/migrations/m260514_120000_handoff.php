<?php

namespace cstudiossro\craftcschatbot\migrations;

use craft\db\Migration;

class m260514_120000_handoff extends Migration
{
    public function safeUp(): bool
    {
        $sessions = '{{%chatbot_sessions}}';
        $messages = '{{%chatbot_messages}}';

        if (!$this->columnExists($sessions, 'handoffStatus')) {
            $this->addColumn($sessions, 'handoffStatus', $this->string(20)->notNull()->defaultValue('none'));
        }
        if (!$this->columnExists($sessions, 'handoffAdminId')) {
            $this->addColumn($sessions, 'handoffAdminId', $this->integer());
        }
        if (!$this->columnExists($sessions, 'handoffRequestedAt')) {
            $this->addColumn($sessions, 'handoffRequestedAt', $this->dateTime());
        }
        if (!$this->columnExists($sessions, 'handoffStartedAt')) {
            $this->addColumn($sessions, 'handoffStartedAt', $this->dateTime());
        }
        if (!$this->columnExists($sessions, 'handoffEndedAt')) {
            $this->addColumn($sessions, 'handoffEndedAt', $this->dateTime());
        }
        if (!$this->columnExists($sessions, 'adminLastReadAt')) {
            $this->addColumn($sessions, 'adminLastReadAt', $this->dateTime());
        }
        if (!$this->columnExists($sessions, 'userLastReadAt')) {
            $this->addColumn($sessions, 'userLastReadAt', $this->dateTime());
        }
        if (!$this->columnExists($sessions, 'lowConfStreak')) {
            $this->addColumn($sessions, 'lowConfStreak', $this->integer()->notNull()->defaultValue(0));
        }

        if (!$this->columnExists($messages, 'adminId')) {
            $this->addColumn($messages, 'adminId', $this->integer());
        }

        $this->createIndex(null, $sessions, ['handoffStatus']);
        $this->createIndex(null, $sessions, ['handoffStatus', 'dateUpdated']);

        return true;
    }

    public function safeDown(): bool
    {
        $sessions = '{{%chatbot_sessions}}';
        $messages = '{{%chatbot_messages}}';
        foreach ([
            'handoffStatus', 'handoffAdminId', 'handoffRequestedAt',
            'handoffStartedAt', 'handoffEndedAt', 'adminLastReadAt',
            'userLastReadAt', 'lowConfStreak',
        ] as $c) {
            if ($this->columnExists($sessions, $c)) {
                $this->dropColumn($sessions, $c);
            }
        }
        if ($this->columnExists($messages, 'adminId')) {
            $this->dropColumn($messages, 'adminId');
        }
        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $schema = $this->db->getTableSchema($table, true);
        return $schema && isset($schema->columns[$column]);
    }
}
