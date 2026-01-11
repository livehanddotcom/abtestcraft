<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Add goals table for multi-goal conversion tracking
 */
class m250107_000001_add_goals_table extends Migration
{
    public function safeUp(): bool
    {
        // Create goals table
        $this->createTable('{{%abtestcraft_goals}}', [
            'id' => $this->primaryKey(),
            'testId' => $this->integer()->notNull(),
            'goalType' => $this->string(50)->notNull(), // form, phone, email, download, page, custom
            'isEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'config' => $this->json()->null(), // Flexible config per goal type
            'sortOrder' => $this->smallInteger()->unsigned()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Add indexes
        $this->createIndex(null, '{{%abtestcraft_goals}}', ['testId']);
        $this->createIndex(null, '{{%abtestcraft_goals}}', ['goalType']);
        $this->createIndex(null, '{{%abtestcraft_goals}}', ['testId', 'goalType'], true);

        // Add foreign key
        $this->addForeignKey(
            null,
            '{{%abtestcraft_goals}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Add goalType column to daily_stats if not exists (for per-goal stats)
        if (!$this->db->columnExists('{{%abtestcraft_daily_stats}}', 'goalType')) {
            $this->addColumn(
                '{{%abtestcraft_daily_stats}}',
                'goalType',
                $this->string(50)->null()->after('conversions')
            );
        }

        // Add goalType column to visitors for tracking which goal converted
        if (!$this->db->columnExists('{{%abtestcraft_visitors}}', 'goalId')) {
            $this->addColumn(
                '{{%abtestcraft_visitors}}',
                'goalId',
                $this->integer()->null()->after('conversionType')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Remove columns first
        if ($this->db->columnExists('{{%abtestcraft_visitors}}', 'goalId')) {
            $this->dropColumn('{{%abtestcraft_visitors}}', 'goalId');
        }
        if ($this->db->columnExists('{{%abtestcraft_daily_stats}}', 'goalType')) {
            $this->dropColumn('{{%abtestcraft_daily_stats}}', 'goalType');
        }

        // Drop goals table
        $this->dropTableIfExists('{{%abtestcraft_goals}}');

        return true;
    }
}
