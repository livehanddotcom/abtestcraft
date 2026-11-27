<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Agency workflow (1.3.0): scheduling, serving the winner, auto-stop, the
 * safety guardrail, and a small key/value table for plugin state (such as
 * when the weekly summary was last sent).
 */
class m261126_000002_agency_workflow extends Migration
{
    private const TESTS = '{{%abtestcraft_tests}}';
    private const STATE = '{{%abtestcraft_state}}';

    public function safeUp(): bool
    {
        $columns = [
            'scheduledStartAt' => $this->dateTime()->null(),
            'scheduledEndAt' => $this->dateTime()->null(),
            'serveWinner' => $this->boolean()->notNull()->defaultValue(false),
            'whenWinnerFound' => $this->string(20)->notNull()->defaultValue('notify'),
            'guardrail' => $this->json()->null(),
            'guardrailTriggeredAt' => $this->dateTime()->null(),
            'guardrailMessage' => $this->text()->null(),
        ];
        foreach ($columns as $name => $type) {
            if (!$this->db->columnExists(self::TESTS, $name)) {
                $this->addColumn(self::TESTS, $name, $type);
            }
        }

        if (!$this->db->tableExists(self::STATE)) {
            $this->createTable(self::STATE, [
                'id' => $this->primaryKey(),
                'key' => $this->string(100)->notNull(),
                'value' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::STATE, ['key'], true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $columns = [
            'scheduledStartAt',
            'scheduledEndAt',
            'serveWinner',
            'whenWinnerFound',
            'guardrail',
            'guardrailTriggeredAt',
            'guardrailMessage',
        ];
        foreach ($columns as $name) {
            if ($this->db->columnExists(self::TESTS, $name)) {
                $this->dropColumn(self::TESTS, $name);
            }
        }
        $this->dropTableIfExists(self::STATE);

        return true;
    }
}
