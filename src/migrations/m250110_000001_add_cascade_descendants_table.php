<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Migration to add cascade descendants table for nested entry support
 */
class m250110_000001_add_cascade_descendants_table extends Migration
{
    public function safeUp(): bool
    {
        // Create the test descendants table
        $this->createTable('{{%abtestcraft_test_descendants}}', [
            'id' => $this->primaryKey(),
            'testId' => $this->integer()->notNull(),
            'controlEntryId' => $this->integer()->notNull(),
            'descendantEntryId' => $this->integer()->notNull(),
            'variantAncestorId' => $this->integer()->notNull(),
            'depth' => $this->integer()->notNull()->defaultValue(1),
            'siteId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['testId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['descendantEntryId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['controlEntryId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['siteId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['testId', 'descendantEntryId'], true);

        // Add foreign keys
        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['controlEntryId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['descendantEntryId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['variantAncestorId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        // Drop foreign keys first
        if ($this->db->tableExists('{{%abtestcraft_test_descendants}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_test_descendants}}');
        }

        // Drop the table
        $this->dropTableIfExists('{{%abtestcraft_test_descendants}}');

        return true;
    }
}
