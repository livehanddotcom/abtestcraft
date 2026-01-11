<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;
use livehand\abtestcraft\services\TestsService;
use livehand\abtestcraft\ABTestCraft;
use DateTime;

/**
 * Integration tests for TestsService
 *
 * These tests verify test lifecycle management with real database operations.
 * Uses raw SQL inserts to bypass foreign key constraints on entry IDs.
 */
class TestsServiceIntegrationTest extends Unit
{
    private TestsService $service;
    private array $createdTestIds = [];

    protected function _before(): void
    {
        $this->service = ABTestCraft::getInstance()->tests;
        $this->cleanupTestData();
    }

    protected function _after(): void
    {
        $this->cleanupTestData();
    }

    /**
     * Clean up test data
     */
    private function cleanupTestData(): void
    {
        foreach ($this->createdTestIds as $testId) {
            DailyStatsRecord::deleteAll(['testId' => $testId]);
            VisitorRecord::deleteAll(['testId' => $testId]);
            GoalRecord::deleteAll(['testId' => $testId]);
            TestRecord::deleteAll(['id' => $testId]);
        }
        $this->createdTestIds = [];

        // Also clean up by handle pattern
        $testRecords = TestRecord::find()
            ->where(['like', 'handle', 'tests-service-test%', false])
            ->all();
        foreach ($testRecords as $record) {
            DailyStatsRecord::deleteAll(['testId' => $record->id]);
            VisitorRecord::deleteAll(['testId' => $record->id]);
            GoalRecord::deleteAll(['testId' => $record->id]);
            $record->delete();
        }
    }

    /**
     * Get valid element IDs from the database for FK constraints
     * Uses any element type since FK references the elements table.
     * Creates dummy elements if not enough exist.
     */
    private function getValidEntryIds(): array
    {
        $elements = (new Query())
            ->select(['id'])
            ->from('{{%elements}}')
            ->limit(2)
            ->column();

        // If we don't have 2 elements, create dummy ones for testing
        while (count($elements) < 2) {
            $id = $this->createDummyElement();
            if ($id) {
                $elements[] = $id;
            } else {
                break;
            }
        }

        if (count($elements) < 2) {
            $this->markTestSkipped('Need at least 2 elements in the database for integration tests');
        }

        return [(int)$elements[0], (int)$elements[1]];
    }

    /**
     * Create a dummy element for FK constraint testing
     */
    private function createDummyElement(): ?int
    {
        $uid = StringHelper::UUID();
        $now = (new DateTime())->format('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()->insert('{{%elements}}', [
            'type' => 'craft\\elements\\Entry',
            'enabled' => true,
            'archived' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => $uid,
        ])->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Create a test record for testing
     */
    private function createTestRecord(array $attributes = []): TestRecord
    {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        [$controlEntryId, $variantEntryId] = $this->getValidEntryIds();
        $handle = $attributes['handle'] ?? 'tests-service-test-' . uniqid();

        $record = new TestRecord();
        $record->siteId = $siteId;
        $record->name = $attributes['name'] ?? 'Tests Service Test';
        $record->handle = $handle;
        $record->status = $attributes['status'] ?? Test::STATUS_DRAFT;
        $record->controlEntryId = $attributes['controlEntryId'] ?? $controlEntryId;
        $record->variantEntryId = $attributes['variantEntryId'] ?? $variantEntryId;
        $record->trafficSplit = $attributes['trafficSplit'] ?? 50;
        $record->goalType = $attributes['goalType'] ?? Goal::TYPE_PAGE;
        $record->hypothesis = $attributes['hypothesis'] ?? null;
        $record->variantDescription = $attributes['variantDescription'] ?? null;
        $record->learnings = $attributes['learnings'] ?? null;
        $record->startedAt = $attributes['startedAt'] ?? null;
        $record->endedAt = $attributes['endedAt'] ?? null;
        $record->winnerVariant = $attributes['winnerVariant'] ?? null;
        $record->dateDeleted = $attributes['dateDeleted'] ?? null;
        $record->save(false);

        $this->createdTestIds[] = $record->id;

        return $record;
    }

    /**
     * Create an enabled goal for a test
     */
    private function createEnabledGoal(int $testId): void
    {
        $goal = new Goal();
        $goal->testId = $testId;
        $goal->goalType = Goal::TYPE_PAGE;
        $goal->isEnabled = true;
        $goal->setPageConfig('/thank-you', Goal::MATCH_EXACT);
        ABTestCraft::getInstance()->goals->saveGoal($goal);
    }

    // ========================================
    // Get Tests Tests
    // ========================================

    /**
     * Test getAllTests returns tests with pagination
     */
    public function testGetAllTestsPagination(): void
    {
        // Create 5 tests
        for ($i = 0; $i < 5; $i++) {
            $this->createTestRecord(['name' => "Pagination Test $i"]);
        }

        // Get with limit
        $tests = $this->service->getAllTests(null, 3, 0);
        $this->assertGreaterThanOrEqual(3, count($tests), 'Should return at least 3 tests with limit');

        // Get with offset
        $tests = $this->service->getAllTests(null, 2, 2);
        $this->assertGreaterThanOrEqual(2, count($tests), 'Should return at least 2 tests with offset');
    }

    /**
     * Test getAllTests excludes soft-deleted tests by default
     */
    public function testGetAllTestsExcludesTrashed(): void
    {
        $activeRecord = $this->createTestRecord(['name' => 'Active Test']);
        $trashedRecord = $this->createTestRecord([
            'name' => 'Trashed Test',
            'dateDeleted' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $tests = $this->service->getAllTests();

        $foundActive = false;
        $foundTrashed = false;
        foreach ($tests as $test) {
            if ($test->id === $activeRecord->id) {
                $foundActive = true;
            }
            if ($test->id === $trashedRecord->id) {
                $foundTrashed = true;
            }
        }

        $this->assertTrue($foundActive, 'Active test should be found');
        $this->assertFalse($foundTrashed, 'Trashed test should not be found');
    }

    /**
     * Test getAllTests with includeTrashed flag
     */
    public function testGetAllTestsIncludesTrashed(): void
    {
        $trashedRecord = $this->createTestRecord([
            'name' => 'Trashed Test',
            'dateDeleted' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $tests = $this->service->getAllTests(null, 0, 0, true);

        $found = false;
        foreach ($tests as $t) {
            if ($t->id === $trashedRecord->id) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Trashed test should be found when includeTrashed=true');
    }

    /**
     * Test getTestsByStatus returns correct tests for 'active' filter
     */
    public function testGetTestsByStatusActive(): void
    {
        $draftRecord = $this->createTestRecord(['status' => Test::STATUS_DRAFT]);
        $runningRecord = $this->createTestRecord([
            'status' => Test::STATUS_RUNNING,
            'startedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);
        $completedRecord = $this->createTestRecord([
            'status' => Test::STATUS_COMPLETED,
            'endedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $activeTests = $this->service->getTestsByStatus('active');

        $foundDraft = false;
        $foundRunning = false;
        $foundCompleted = false;

        foreach ($activeTests as $test) {
            if ($test->id === $draftRecord->id) {
                $foundDraft = true;
            }
            if ($test->id === $runningRecord->id) {
                $foundRunning = true;
            }
            if ($test->id === $completedRecord->id) {
                $foundCompleted = true;
            }
        }

        $this->assertTrue($foundDraft, 'Draft should be in active');
        $this->assertTrue($foundRunning, 'Running should be in active');
        $this->assertFalse($foundCompleted, 'Completed should not be in active');
    }

    /**
     * Test getTestsByStatus returns correct tests for 'completed' filter
     */
    public function testGetTestsByStatusCompleted(): void
    {
        $draftRecord = $this->createTestRecord(['status' => Test::STATUS_DRAFT]);
        $completedRecord = $this->createTestRecord([
            'status' => Test::STATUS_COMPLETED,
            'endedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $completedTests = $this->service->getTestsByStatus('completed');

        $foundCompleted = false;
        $foundDraft = false;

        foreach ($completedTests as $test) {
            if ($test->id === $completedRecord->id) {
                $foundCompleted = true;
            }
            if ($test->id === $draftRecord->id) {
                $foundDraft = true;
            }
        }

        $this->assertTrue($foundCompleted, 'Completed test should be found');
        $this->assertFalse($foundDraft, 'Draft test should not be in completed');
    }

    /**
     * Test getTestsByStatus returns trashed tests
     */
    public function testGetTestsByStatusTrashed(): void
    {
        $trashedRecord = $this->createTestRecord([
            'name' => 'Trashed Test',
            'dateDeleted' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $trashedTests = $this->service->getTestsByStatus('trashed');

        $found = false;
        foreach ($trashedTests as $t) {
            if ($t->id === $trashedRecord->id) {
                $found = true;
                $this->assertNotNull($t->dateDeleted, 'Trashed test should have dateDeleted');
                break;
            }
        }

        $this->assertTrue($found, 'Trashed test should be in trashed list');
    }

    /**
     * Test getTestCounts returns correct distribution
     */
    public function testGetTestCounts(): void
    {
        // Create tests with different statuses
        $this->createTestRecord(['status' => Test::STATUS_DRAFT]);
        $this->createTestRecord([
            'status' => Test::STATUS_RUNNING,
            'startedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);
        $this->createTestRecord([
            'status' => Test::STATUS_COMPLETED,
            'endedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);
        $this->createTestRecord([
            'status' => Test::STATUS_DRAFT,
            'dateDeleted' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $counts = $this->service->getTestCounts();

        $this->assertArrayHasKey('active', $counts);
        $this->assertArrayHasKey('completed', $counts);
        $this->assertArrayHasKey('trashed', $counts);
        $this->assertArrayHasKey('all', $counts);

        // We added 2 active (draft, running), 1 completed, 1 trashed
        $this->assertGreaterThanOrEqual(2, $counts['active']);
        $this->assertGreaterThanOrEqual(1, $counts['completed']);
        $this->assertGreaterThanOrEqual(1, $counts['trashed']);
    }

    // ========================================
    // Get Test By Methods Tests
    // ========================================

    /**
     * Test getTestById returns correct test
     */
    public function testGetTestById(): void
    {
        $record = $this->createTestRecord(['name' => 'Find By ID Test']);

        $retrieved = $this->service->getTestById($record->id);

        $this->assertNotNull($retrieved);
        $this->assertEquals($record->id, $retrieved->id);
        $this->assertEquals('Find By ID Test', $retrieved->name);
    }

    /**
     * Test getTestById returns null for non-existent ID
     */
    public function testGetTestByIdNotFound(): void
    {
        $retrieved = $this->service->getTestById(999999);

        $this->assertNull($retrieved);
    }

    /**
     * Test getTestByHandle returns correct test
     */
    public function testGetTestByHandle(): void
    {
        $record = $this->createTestRecord(['handle' => 'find-by-handle-test']);

        $retrieved = $this->service->getTestByHandle('find-by-handle-test');

        $this->assertNotNull($retrieved);
        $this->assertEquals($record->id, $retrieved->id);
    }

    /**
     * Test getTestByHandle returns null for non-existent handle
     */
    public function testGetTestByHandleNotFound(): void
    {
        $retrieved = $this->service->getTestByHandle('non-existent-handle-12345');

        $this->assertNull($retrieved);
    }

    // ========================================
    // Start Test Tests
    // ========================================

    /**
     * Test startTest requires enabled goals
     */
    public function testStartTestRequiresGoals(): void
    {
        $record = $this->createTestRecord(['status' => Test::STATUS_DRAFT]);
        $test = $this->service->getTestById($record->id);
        // No goals created

        $result = $this->service->startTest($test);

        $this->assertIsString($result, 'startTest should return error message');
        $this->assertStringContainsString('goal', strtolower($result));
        $this->assertEquals(Test::STATUS_DRAFT, $test->status, 'Status should not change');
    }

    /**
     * Test startTest sets status to running when goals exist
     */
    public function testStartTestSetsStatus(): void
    {
        $record = $this->createTestRecord(['status' => Test::STATUS_DRAFT]);
        $this->createEnabledGoal($record->id);

        $test = $this->service->getTestById($record->id);
        $result = $this->service->startTest($test);

        $this->assertTrue($result, 'startTest should return true');
        $this->assertEquals(Test::STATUS_RUNNING, $test->status);
        $this->assertNotNull($test->startedAt, 'startedAt should be set');
    }

    /**
     * Test startTest from paused state
     */
    public function testStartTestFromPaused(): void
    {
        $record = $this->createTestRecord([
            'status' => Test::STATUS_PAUSED,
            'startedAt' => (new DateTime('-1 day'))->format('Y-m-d H:i:s'),
        ]);
        $this->createEnabledGoal($record->id);

        $test = $this->service->getTestById($record->id);
        $result = $this->service->startTest($test);

        $this->assertTrue($result, 'Should be able to start from paused');
        $this->assertEquals(Test::STATUS_RUNNING, $test->status);
    }

    // ========================================
    // Pause Test Tests
    // ========================================

    /**
     * Test pauseTest from running state
     */
    public function testPauseTestFromRunning(): void
    {
        $record = $this->createTestRecord([
            'status' => Test::STATUS_RUNNING,
            'startedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $test = $this->service->getTestById($record->id);
        $result = $this->service->pauseTest($test);

        $this->assertTrue($result, 'pauseTest should return true');
        $this->assertEquals(Test::STATUS_PAUSED, $test->status);
    }

    /**
     * Test pauseTest from non-running state fails
     */
    public function testPauseTestFromDraft(): void
    {
        $record = $this->createTestRecord(['status' => Test::STATUS_DRAFT]);

        $test = $this->service->getTestById($record->id);
        $result = $this->service->pauseTest($test);

        $this->assertFalse($result, 'Cannot pause a draft test');
        $this->assertEquals(Test::STATUS_DRAFT, $test->status, 'Status should not change');
    }

    // ========================================
    // Complete Test Tests
    // ========================================

    /**
     * Test completeTest sets status and winner
     */
    public function testCompleteTestSetsWinner(): void
    {
        $record = $this->createTestRecord([
            'status' => Test::STATUS_RUNNING,
            'startedAt' => (new DateTime('-1 day'))->format('Y-m-d H:i:s'),
        ]);

        $test = $this->service->getTestById($record->id);
        $result = $this->service->completeTest($test, Test::VARIANT_VARIANT);

        $this->assertTrue($result, 'completeTest should return true');
        $this->assertEquals(Test::STATUS_COMPLETED, $test->status);
        $this->assertNotNull($test->endedAt, 'endedAt should be set');
        $this->assertEquals(Test::VARIANT_VARIANT, $test->winnerVariant);

        // Verify in database
        $dbRecord = TestRecord::findOne($record->id);
        $this->assertEquals(Test::STATUS_COMPLETED, $dbRecord->status);
        $this->assertEquals(Test::VARIANT_VARIANT, $dbRecord->winnerVariant);
    }

    /**
     * Test completeTest with no winner
     */
    public function testCompleteTestNoWinner(): void
    {
        $record = $this->createTestRecord([
            'status' => Test::STATUS_RUNNING,
            'startedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $test = $this->service->getTestById($record->id);
        $result = $this->service->completeTest($test, null);

        $this->assertTrue($result);
        $this->assertEquals(Test::STATUS_COMPLETED, $test->status);
        $this->assertNull($test->winnerVariant);
    }

    // ========================================
    // Delete/Restore Tests
    // ========================================

    /**
     * Test deleteTest soft deletes
     */
    public function testDeleteTestSoftDeletes(): void
    {
        $record = $this->createTestRecord();
        $test = $this->service->getTestById($record->id);

        $result = $this->service->deleteTest($test);

        $this->assertTrue($result, 'deleteTest should return true');
        $this->assertNotNull($test->dateDeleted, 'dateDeleted should be set');

        // Verify record still exists
        $dbRecord = TestRecord::findOne($record->id);
        $this->assertNotNull($dbRecord, 'Record should still exist');
        $this->assertNotNull($dbRecord->dateDeleted, 'Record should have dateDeleted');
    }

    /**
     * Test restoreTest clears dateDeleted
     */
    public function testRestoreTestFromTrash(): void
    {
        $record = $this->createTestRecord([
            'dateDeleted' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        $test = $this->service->getTestById($record->id);
        // Need to get test with includeTrashed
        $tests = $this->service->getAllTests(null, 0, 0, true);
        $test = null;
        foreach ($tests as $t) {
            if ($t->id === $record->id) {
                $test = $t;
                break;
            }
        }

        $this->assertNotNull($test, 'Should find trashed test');
        $this->assertNotNull($test->dateDeleted, 'Should be trashed first');

        $result = $this->service->restoreTest($test);

        $this->assertTrue($result, 'restoreTest should return true');
        $this->assertNull($test->dateDeleted, 'dateDeleted should be cleared');

        // Verify in database
        $dbRecord = TestRecord::findOne($record->id);
        $this->assertNull($dbRecord->dateDeleted);
    }

    /**
     * Test restoreTest fails for non-trashed test
     */
    public function testRestoreTestNotTrashed(): void
    {
        $record = $this->createTestRecord();
        $test = $this->service->getTestById($record->id);

        $result = $this->service->restoreTest($test);

        $this->assertFalse($result, 'Cannot restore a non-trashed test');
    }

    /**
     * Test hardDeleteTest permanently removes test
     */
    public function testHardDeleteRemovesRecord(): void
    {
        $record = $this->createTestRecord();
        $testId = $record->id;
        $test = $this->service->getTestById($testId);

        // Remove from tracking since we're hard deleting
        $this->createdTestIds = array_filter($this->createdTestIds, fn($id) => $id !== $testId);

        $result = $this->service->hardDeleteTest($test);

        $this->assertTrue($result, 'hardDeleteTest should return true');

        // Verify record is gone
        $dbRecord = TestRecord::findOne($testId);
        $this->assertNull($dbRecord, 'Record should be permanently deleted');
    }

    // ========================================
    // Total Count Tests
    // ========================================

    /**
     * Test getTotalTestCount returns correct count
     */
    public function testGetTotalTestCount(): void
    {
        $initialCount = $this->service->getTotalTestCount();

        // Create some tests
        $this->createTestRecord();
        $this->createTestRecord();

        $newCount = $this->service->getTotalTestCount();

        $this->assertEquals($initialCount + 2, $newCount, 'Count should increase by 2');
    }

    /**
     * Test getTotalTestCount excludes trashed by default
     */
    public function testGetTotalTestCountExcludesTrashed(): void
    {
        $initialCount = $this->service->getTotalTestCount();

        $record = $this->createTestRecord();

        $countBeforeTrash = $this->service->getTotalTestCount();
        $this->assertEquals($initialCount + 1, $countBeforeTrash);

        // Soft delete via service
        $test = $this->service->getTestById($record->id);
        $this->service->deleteTest($test);

        $countAfterTrash = $this->service->getTotalTestCount();
        $this->assertEquals($initialCount, $countAfterTrash, 'Count should exclude trashed');
    }

    // ========================================
    // Active Tests Query
    // ========================================

    /**
     * Test getActiveTests returns only running tests
     */
    public function testGetActiveTestsReturnsRunning(): void
    {
        $runningRecord = $this->createTestRecord([
            'status' => Test::STATUS_RUNNING,
            'startedAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);
        $draftRecord = $this->createTestRecord(['status' => Test::STATUS_DRAFT]);

        $activeTests = $this->service->getActiveTests();

        $foundRunning = false;
        $foundDraft = false;

        foreach ($activeTests as $test) {
            if ($test->id === $runningRecord->id) {
                $foundRunning = true;
            }
            if ($test->id === $draftRecord->id) {
                $foundDraft = true;
            }
        }

        $this->assertTrue($foundRunning, 'Running test should be in active tests');
        $this->assertFalse($foundDraft, 'Draft test should not be in active tests');
    }
}
