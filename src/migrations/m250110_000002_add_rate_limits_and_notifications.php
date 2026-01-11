<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * m250110_000002_add_rate_limits_and_notifications migration
 *
 * Adds:
 * - Rate limits table for database-based rate limiting (multi-server support)
 * - Significance notification timestamp column to tests table
 */
class m250110_000002_add_rate_limits_and_notifications extends Migration
{
    public function safeUp(): bool
    {
        // Create rate limits table for database-based rate limiting
        if (!$this->db->tableExists('{{%abtestcraft_rate_limits}}')) {
            $this->createTable('{{%abtestcraft_rate_limits}}', [
                'id' => $this->primaryKey(),
                'cacheKey' => $this->string(255)->notNull(),
                'requestCount' => $this->integer()->notNull()->defaultValue(1),
                'windowStart' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Unique index on cache key for upsert operations
            $this->createIndex(
                'idx_abtestcraft_rate_limits_cache_key',
                '{{%abtestcraft_rate_limits}}',
                ['cacheKey'],
                true
            );

            // Index on window start for cleanup queries
            $this->createIndex(
                'idx_abtestcraft_rate_limits_window',
                '{{%abtestcraft_rate_limits}}',
                ['windowStart']
            );
        }

        // Add significance notification timestamp to tests table
        if (!$this->db->columnExists('{{%abtestcraft_tests}}', 'significanceNotifiedAt')) {
            $this->addColumn(
                '{{%abtestcraft_tests}}',
                'significanceNotifiedAt',
                $this->dateTime()->null()->after('winnerVariant')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Drop rate limits table
        $this->dropTableIfExists('{{%abtestcraft_rate_limits}}');

        // Remove significance notification column
        if ($this->db->columnExists('{{%abtestcraft_tests}}', 'significanceNotifiedAt')) {
            $this->dropColumn('{{%abtestcraft_tests}}', 'significanceNotifiedAt');
        }

        return true;
    }
}
