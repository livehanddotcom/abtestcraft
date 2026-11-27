<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;
use craft\db\Query;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Test;

/**
 * Clears Blitz's cache for pages of tests that are already running.
 *
 * Before 1.0.1, Blitz could cache a tested page with whichever variant the
 * first visitor got. New saves clear the cache automatically, but tests that
 * were already running need a one-time clear on upgrade. No schema changes;
 * a no-op when Blitz isn't installed.
 *
 * Reads the tests table directly, with only the columns 1.0.0 had: the
 * services expect columns that later migrations add, so using them here made
 * an upgrade from 1.0.0 fail whenever a test was running.
 */
class m261125_000001_clear_page_cache_for_running_tests extends Migration
{
    public function safeUp(): bool
    {
        $plugin = ABTestCraft::getInstance();
        if ($plugin === null) {
            return true;
        }

        $rows = (new Query())
            ->select(['id', 'siteId', 'handle', 'controlEntryId', 'variantEntryId'])
            ->from('{{%abtestcraft_tests}}')
            ->where(['status' => Test::STATUS_RUNNING, 'dateDeleted' => null])
            ->all();

        foreach ($rows as $row) {
            $plugin->pageCache->clearTestPages(new Test([
                'id' => (int) $row['id'],
                'siteId' => (int) $row['siteId'],
                'handle' => (string) $row['handle'],
                'controlEntryId' => $row['controlEntryId'] !== null ? (int) $row['controlEntryId'] : null,
                'variantEntryId' => $row['variantEntryId'] !== null ? (int) $row['variantEntryId'] : null,
            ]));
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Nothing to revert
        return true;
    }
}
