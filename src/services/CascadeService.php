<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\TestDescendantRecord;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\jobs\RebuildCascadeJob;

/**
 * Cascade service - manages descendant relationships for nested entry support in split tests
 */
class CascadeService extends Component
{
    /**
     * Threshold for using async queue job vs synchronous rebuild
     */
    private const ASYNC_THRESHOLD = 50;

    /**
     * Rebuild all descendant mappings for a test
     * Called when test is saved or entry structure changes
     */
    public function rebuildDescendants(Test $test): bool
    {
        if (!$test->id || !$test->controlEntryId || !$test->variantEntryId) {
            return false;
        }

        $controlEntry = Entry::find()
            ->id($test->controlEntryId)
            ->siteId($test->siteId)
            ->status(null)
            ->one();

        if (!$controlEntry) {
            return false;
        }

        // Count descendants to decide sync vs async
        $descendantCount = Entry::find()
            ->descendantOf($controlEntry)
            ->siteId($test->siteId)
            ->status(null)
            ->count();

        if ($descendantCount > self::ASYNC_THRESHOLD) {
            // Push to queue for async processing
            Craft::$app->getQueue()->push(new RebuildCascadeJob([
                'testId' => $test->id,
            ]));
            return true;
        }

        // Process synchronously
        return $this->doRebuildDescendants($test);
    }

    /**
     * Actually rebuild the descendants (called sync or from queue job)
     */
    public function doRebuildDescendants(Test $test): bool
    {
        if (!$test->id || !$test->controlEntryId || !$test->variantEntryId) {
            return false;
        }

        $controlEntry = Entry::find()
            ->id($test->controlEntryId)
            ->siteId($test->siteId)
            ->status(null)
            ->one();

        $variantEntry = Entry::find()
            ->id($test->variantEntryId)
            ->siteId($test->siteId)
            ->status(null)
            ->one();

        if (!$controlEntry || !$variantEntry) {
            return false;
        }

        // Clear existing descendants for this test
        $this->clearDescendants($test->id);

        // Get all descendants of control entry
        $descendants = Entry::find()
            ->descendantOf($controlEntry)
            ->siteId($test->siteId)
            ->status(null)
            ->orderBy(['lft' => SORT_ASC])
            ->all();

        if (empty($descendants)) {
            return true; // No descendants to map
        }

        // Save descendant mappings
        $count = 0;
        foreach ($descendants as $descendant) {
            $depth = $this->calculateDepth($controlEntry, $descendant);

            $record = new TestDescendantRecord();
            $record->testId = $test->id;
            $record->controlEntryId = $controlEntry->id;
            $record->descendantEntryId = $descendant->id;
            $record->variantAncestorId = $variantEntry->id;
            $record->depth = $depth;
            $record->siteId = $test->siteId;

            if ($record->save()) {
                $count++;
            } else {
                Craft::warning(
                    "Split Test: Failed to save descendant mapping for entry {$descendant->id}",
                    __METHOD__
                );
            }
        }

        Craft::info(
            "Split Test: Rebuilt {$count} descendant mappings for test '{$test->handle}'",
            __METHOD__
        );

        return true;
    }

    /**
     * Calculate the depth of a descendant relative to an ancestor
     */
    private function calculateDepth(Entry $ancestor, Entry $descendant): int
    {
        $ancestorLevel = $ancestor->level ?? 1;
        $descendantLevel = $descendant->level ?? 1;

        return max(1, $descendantLevel - $ancestorLevel);
    }

    /**
     * Get cascade info for an entry if it's a descendant of a running test
     * Returns null if no test affects this entry
     */
    public function getCascadeInfo(int $entryId, ?int $siteId = null): ?array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        // Get all cascade records for this entry, ordered by depth (nearest first)
        $records = (new Query())
            ->select(['td.*', 't.status', 't.handle'])
            ->from(['td' => '{{%abtestcraft_test_descendants}}'])
            ->innerJoin(['t' => '{{%abtestcraft_tests}}'], '[[td.testId]] = [[t.id]]')
            ->where([
                'td.descendantEntryId' => $entryId,
                'td.siteId' => $siteId,
                't.status' => Test::STATUS_RUNNING,
            ])
            ->orderBy(['td.depth' => SORT_ASC])
            ->all();

        if (empty($records)) {
            return null;
        }

        // Return the nearest running test (first one)
        $record = $records[0];

        return [
            'testId' => (int) $record['testId'],
            'controlEntryId' => (int) $record['controlEntryId'],
            'descendantEntryId' => (int) $record['descendantEntryId'],
            'variantAncestorId' => (int) $record['variantAncestorId'],
            'depth' => (int) $record['depth'],
            'siteId' => (int) $record['siteId'],
            'testHandle' => $record['handle'],
        ];
    }

    /**
     * Check if an entry is a descendant in any running test
     */
    public function isDescendantOfRunningTest(int $entryId, ?int $siteId = null): bool
    {
        return $this->getCascadeInfo($entryId, $siteId) !== null;
    }

    /**
     * Get the variant ancestor entry for a descendant
     */
    public function getVariantAncestor(int $descendantEntryId, int $testId): ?Entry
    {
        $record = TestDescendantRecord::findOne([
            'descendantEntryId' => $descendantEntryId,
            'testId' => $testId,
        ]);

        if (!$record) {
            return null;
        }

        return Entry::find()
            ->id($record->variantAncestorId)
            ->status(null)
            ->one();
    }

    /**
     * Get the control entry for a test
     */
    public function getControlEntry(int $testId): ?Entry
    {
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            return null;
        }

        return $test->getControlEntry();
    }

    /**
     * Clear all descendant mappings for a test
     */
    public function clearDescendants(int $testId): bool
    {
        return (bool) TestDescendantRecord::deleteAll(['testId' => $testId]);
    }

    /**
     * Handle entry structure changes - update affected tests
     */
    public function handleEntryMoved(Entry $entry): void
    {
        $siteId = $entry->siteId;

        // Find tests where this entry is a descendant
        $affectedTestIds = (new Query())
            ->select(['testId'])
            ->distinct()
            ->from('{{%abtestcraft_test_descendants}}')
            ->where(['descendantEntryId' => $entry->id, 'siteId' => $siteId])
            ->column();

        // Also check if entry became a descendant of any test's control entry
        $runningTests = ABTestCraft::getInstance()->tests->getActiveTests($siteId);

        foreach ($runningTests as $test) {
            if (!$test->controlEntryId) {
                continue;
            }

            $isNowDescendant = Entry::find()
                ->descendantOf($test->controlEntryId)
                ->id($entry->id)
                ->siteId($siteId)
                ->exists();

            $wasDescendant = in_array($test->id, $affectedTestIds);

            // If status changed, rebuild this test's mappings
            if ($isNowDescendant !== $wasDescendant) {
                $affectedTestIds[] = $test->id;
            }
        }

        // Rebuild affected tests
        $affectedTestIds = array_unique($affectedTestIds);
        foreach ($affectedTestIds as $testId) {
            $test = ABTestCraft::getInstance()->tests->getTestById($testId);
            if ($test) {
                $this->rebuildDescendants($test);
            }
        }
    }

    /**
     * Handle entry deletion - clean up cascade mappings
     */
    public function handleEntryDeleted(int $entryId): void
    {
        // Remove any mappings where this entry was a descendant
        TestDescendantRecord::deleteAll(['descendantEntryId' => $entryId]);

        // Remove any mappings where this entry was the control or variant
        // (FK cascade should handle this, but be explicit)
        TestDescendantRecord::deleteAll(['controlEntryId' => $entryId]);
        TestDescendantRecord::deleteAll(['variantAncestorId' => $entryId]);
    }

    /**
     * Get all tests that have a specific entry as a descendant
     */
    public function getTestsAffectingDescendant(int $entryId, ?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $testIds = (new Query())
            ->select(['testId'])
            ->distinct()
            ->from('{{%abtestcraft_test_descendants}}')
            ->where(['descendantEntryId' => $entryId, 'siteId' => $siteId])
            ->column();

        if (empty($testIds)) {
            return [];
        }

        $tests = [];
        foreach ($testIds as $testId) {
            $test = ABTestCraft::getInstance()->tests->getTestById($testId);
            if ($test && $test->isRunning()) {
                $tests[] = $test;
            }
        }

        return $tests;
    }

    /**
     * Check if an entry is the variant entry in any running test
     */
    public function isVariantEntry(int $entryId, ?int $siteId = null): ?Test
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $record = (new Query())
            ->select(['*'])
            ->from('{{%abtestcraft_tests}}')
            ->where([
                'variantEntryId' => $entryId,
                'siteId' => $siteId,
                'status' => Test::STATUS_RUNNING,
            ])
            ->one();

        if (!$record) {
            return null;
        }

        return ABTestCraft::getInstance()->tests->getTestById($record['id']);
    }

    /**
     * Get the control entry's children for a variant entry
     * Used to "borrow" children when variant has none
     */
    public function getControlChildren(Entry $variantEntry): array
    {
        $test = $this->isVariantEntry($variantEntry->id, $variantEntry->siteId);

        if (!$test) {
            return [];
        }

        $controlEntry = $test->getControlEntry();

        if (!$controlEntry) {
            return [];
        }

        // Return control's children with default status filtering
        return $controlEntry->getChildren()->all();
    }
}
