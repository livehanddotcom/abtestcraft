<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;
use livehand\abtestcraft\ABTestCraft;

/**
 * Clears Blitz's cache for pages of tests that are already running.
 *
 * Before 1.0.1, Blitz could cache a tested page with whichever variant the
 * first visitor got. New saves clear the cache automatically, but tests that
 * were already running need a one-time clear on upgrade. No schema changes;
 * a no-op when Blitz isn't installed.
 */
class m261125_000001_clear_page_cache_for_running_tests extends Migration
{
    public function safeUp(): bool
    {
        $plugin = ABTestCraft::getInstance();
        if ($plugin === null) {
            return true;
        }

        foreach ($plugin->tests->getActiveTests() as $test) {
            $plugin->pageCache->clearTestPages($test);
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Nothing to revert
        return true;
    }
}
