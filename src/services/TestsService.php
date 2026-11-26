<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\ABTestCraft;
use DateTime;

/**
 * Tests service - CRUD operations for split tests
 */
class TestsService extends Component
{
    /**
     * Get all tests with optional pagination
     *
     * @param int|string|null $siteId Filter by site ID
     * @param int $limit Maximum number of tests to return (0 for unlimited)
     * @param int $offset Number of tests to skip
     * @param bool $includeTrashed Include soft-deleted tests
     * @return Test[]
     */
    public function getAllTests(
        int|string|null $siteId = null,
        int $limit = 0,
        int $offset = 0,
        bool $includeTrashed = false,
    ): array {
        $query = TestRecord::find();

        if ($siteId) {
            $query->where(['siteId' => (int) $siteId]);
        }

        if (!$includeTrashed) {
            $query->andWhere(['dateDeleted' => null]);
        }

        $query->orderBy(['dateCreated' => SORT_DESC]);

        if ($limit > 0) {
            $query->limit($limit)->offset($offset);
        }

        return array_map(fn($record) => $this->createModelFromRecord($record), $query->all());
    }

    /**
     * Get total count of tests
     *
     * @param int|string|null $siteId Filter by site ID
     * @param bool $includeTrashed Include soft-deleted tests
     * @return int Total number of tests
     */
    public function getTotalTestCount(int|string|null $siteId = null, bool $includeTrashed = false): int
    {
        $query = (new Query())->from('{{%abtestcraft_tests}}');

        if ($siteId) {
            $query->where(['siteId' => (int) $siteId]);
        }

        if (!$includeTrashed) {
            $query->andWhere(['dateDeleted' => null]);
        }

        return (int) $query->count();
    }

    /**
     * Get active tests (running)
     */
    public function getActiveTests(int|string|null $siteId = null): array
    {
        $query = TestRecord::find()
            ->where(['status' => Test::STATUS_RUNNING])
            ->andWhere(['dateDeleted' => null]);

        if ($siteId) {
            $query->andWhere(['siteId' => (int) $siteId]);
        }

        return array_map(fn($record) => $this->createModelFromRecord($record), $query->all());
    }

    /**
     * Get tests filtered by status category
     *
     * @param string $filter 'active' (draft/running/paused), 'completed', 'trashed', or 'all'
     */
    public function getTestsByStatus(string $filter = 'active', int|string|null $siteId = null): array
    {
        $query = TestRecord::find();

        if ($siteId) {
            $query->where(['siteId' => (int) $siteId]);
        }

        if ($filter === 'trashed') {
            $query->andWhere(['not', ['dateDeleted' => null]]);
        } else {
            // Exclude trashed for all other filters
            $query->andWhere(['dateDeleted' => null]);

            if ($filter === 'active') {
                $query->andWhere(['in', 'status', [Test::STATUS_DRAFT, Test::STATUS_RUNNING, Test::STATUS_PAUSED]]);
            } elseif ($filter === 'completed') {
                $query->andWhere(['status' => Test::STATUS_COMPLETED]);
            }
            // 'all' applies no status filter (but still excludes trashed)
        }

        $query->orderBy(['dateCreated' => SORT_DESC]);

        return array_map(fn($record) => $this->createModelFromRecord($record), $query->all());
    }

    /**
     * Get test counts by status category
     *
     * @return array{active: int, completed: int, trashed: int, all: int}
     */
    public function getTestCounts(int|string|null $siteId = null): array
    {
        $baseQuery = (new Query())->from('{{%abtestcraft_tests}}');

        if ($siteId) {
            $baseQuery->where(['siteId' => (int) $siteId]);
        }

        // Non-trashed query base
        $notTrashedQuery = (clone $baseQuery)->andWhere(['dateDeleted' => null]);

        $activeCount = (clone $notTrashedQuery)
            ->andWhere(['in', 'status', [Test::STATUS_DRAFT, Test::STATUS_RUNNING, Test::STATUS_PAUSED]])
            ->count();

        $completedCount = (clone $notTrashedQuery)
            ->andWhere(['status' => Test::STATUS_COMPLETED])
            ->count();

        $trashedCount = (clone $baseQuery)
            ->andWhere(['not', ['dateDeleted' => null]])
            ->count();

        return [
            'active' => (int) $activeCount,
            'completed' => (int) $completedCount,
            'trashed' => (int) $trashedCount,
            'all' => (int) $activeCount + (int) $completedCount,
        ];
    }

    /**
     * Get a test by ID
     *
     * Accepts strings because IDs often arrive from request params or
     * DB columns as strings, and this file uses strict_types.
     */
    public function getTestById(int|string $id): ?Test
    {
        $record = TestRecord::findOne((int) $id);

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Get a test by handle
     */
    public function getTestByHandle(string $handle): ?Test
    {
        $record = TestRecord::findOne(['handle' => $handle]);

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Get test by control entry ID
     */
    public function getTestByControlEntryId(int|string $entryId, int|string|null $siteId = null): ?Test
    {
        $query = TestRecord::find()
            ->where(['controlEntryId' => (int) $entryId])
            ->andWhere(['status' => Test::STATUS_RUNNING])
            ->andWhere(['dateDeleted' => null]);

        if ($siteId) {
            $query->andWhere(['siteId' => (int) $siteId]);
        }

        $record = $query->one();

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Save a test
     */
    public function saveTest(Test $test): bool
    {
        $test->generateHandle();

        if (!$test->validate()) {
            return false;
        }

        if (!$this->validateUniqueHandle($test)) {
            return false;
        }

        // A page can only be in one running test at a time
        if ($test->isRunning() && ($overlapping = $this->getOverlappingRunningTest($test))) {
            $test->addError('controlEntryId', $this->overlapMessage($overlapping));
            return false;
        }

        if ($test->id) {
            $record = TestRecord::findOne($test->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new TestRecord();
        }

        // Compare with what's stored, to clear the page cache only when this
        // save starts the test or changes which pages it covers (not on e.g.
        // saving learnings)
        $wasRunning = !$record->getIsNewRecord()
            && $record->status === Test::STATUS_RUNNING
            && $record->dateDeleted === null;
        $pagesChanged = $record->getIsNewRecord()
            || (int) $record->controlEntryId !== (int) $test->controlEntryId
            || (int) $record->variantEntryId !== (int) $test->variantEntryId
            || (int) $record->siteId !== (int) ($test->siteId ?? $record->siteId);

        $record->siteId = $test->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $record->name = $test->name;
        $record->handle = $test->handle;
        $record->hypothesis = $test->hypothesis;
        $record->variantDescription = $test->variantDescription;
        $record->learnings = $test->learnings;
        $record->status = $test->status;
        $record->controlEntryId = $test->controlEntryId;
        $record->variantEntryId = $test->variantEntryId;
        $record->trafficSplit = $test->trafficSplit;
        $record->goalType = $test->goalType;
        $record->goalValue = $test->goalValue;
        $record->primaryGoalType = $test->primaryGoalType;
        $record->startedAt = $test->startedAt;
        $record->endedAt = $test->endedAt;
        $record->winnerVariant = $test->winnerVariant;

        if (!$record->save()) {
            $test->addErrors($record->getErrors());
            return false;
        }

        $test->id = (int) $record->id;
        $test->dateCreated = self::dbDate($record->dateCreated);
        $test->dateUpdated = self::dbDate($record->dateUpdated);
        $test->uid = $record->uid;

        // A running test's pages must not be served from a full-page cache
        // (e.g. Blitz), or visitors keep getting one cached variant. Covers
        // starting a test and pointing a running one at different entries.
        $clearPageCache = $test->isRunning() && (!$wasRunning || $pagesChanged);

        // Rebuild cascade descendant mappings for this test. If that's queued
        // (large structures), the job clears the page cache again once child
        // pages are mapped, as some may be cached in the meantime.
        if ($test->controlEntryId && $test->variantEntryId) {
            ABTestCraft::getInstance()->cascade->rebuildDescendants($test, $clearPageCache);
        }

        if ($clearPageCache) {
            ABTestCraft::getInstance()->pageCache->clearTestPages($test);
        }

        return true;
    }

    /**
     * Another running test that covers this test's page: the same control
     * entry, or one test's page being a child page of the other's. Only one
     * test can run on a page; otherwise one of them silently gets no traffic.
     */
    public function getOverlappingRunningTest(Test $test): ?Test
    {
        if (!$test->controlEntryId) {
            return null;
        }

        $query = TestRecord::find()
            ->where(['status' => Test::STATUS_RUNNING, 'dateDeleted' => null])
            ->andWhere(['siteId' => $test->siteId ?? Craft::$app->getSites()->getCurrentSite()->id]);
        if ($test->id) {
            $query->andWhere(['not', ['id' => $test->id]]);
        }

        /** @var TestRecord[] $others */
        $others = $query->all();
        if ($others === []) {
            return null;
        }

        $byControl = [];
        foreach ($others as $record) {
            if ((int) $record->controlEntryId === (int) $test->controlEntryId) {
                return $this->createModelFromRecord($record);
            }
            $byControl[(int) $record->controlEntryId] = $record;
        }

        // This page is a child page of another running test's page
        $parentTestId = (new Query())
            ->select(['testId'])
            ->from('{{%abtestcraft_test_descendants}}')
            ->where([
                'descendantEntryId' => $test->controlEntryId,
                'testId' => array_map(fn(TestRecord $record) => (int) $record->id, $others),
            ])
            ->scalar();
        if ($parentTestId) {
            foreach ($others as $record) {
                if ((int) $record->id === (int) $parentTestId) {
                    return $this->createModelFromRecord($record);
                }
            }
        }

        // Another running test's page is a child page of this one
        $childIds = Entry::find()
            ->descendantOf($test->controlEntryId)
            ->id(array_keys($byControl))
            ->siteId($test->siteId)
            ->status(null)
            ->ids();
        if ($childIds !== []) {
            return $this->createModelFromRecord($byControl[(int) $childIds[0]]);
        }

        return null;
    }

    private function overlapMessage(Test $overlapping): string
    {
        return "This page is already part of the running test \"{$overlapping->name}\". "
            . 'Pause or complete that test first: a page can only be in one running test at a time.';
    }

    /**
     * Ensure no other test, including one in the trash, uses the test's handle.
     *
     * Handles have a DB unique index that also covers trashed tests, so a
     * duplicate would otherwise surface as an IntegrityException (HTTP 500)
     * instead of a form error.
     */
    private function validateUniqueHandle(Test $test): bool
    {
        $query = (new Query())
            ->select(['dateDeleted'])
            ->from('{{%abtestcraft_tests}}')
            ->where(['handle' => $test->handle]);

        if ($test->id) {
            $query->andWhere(['not', ['id' => $test->id]]);
        }

        $existing = $query->one();

        if (!$existing) {
            return true;
        }

        $message = $existing['dateDeleted'] !== null
            ? 'This handle is used by a test in the trash. ' .
                'Choose a different handle, or restore or permanently delete that test from the Trash tab.'
            : 'This handle is already used by another test.';

        $test->addError('handle', Craft::t('abtestcraft', $message));

        return false;
    }

    /**
     * Soft delete a test (move to trash)
     */
    public function deleteTest(Test $test): bool
    {
        if (!$test->id) {
            return false;
        }

        $record = TestRecord::findOne($test->id);

        if (!$record) {
            return false;
        }

        // Soft delete by setting dateDeleted
        $record->dateDeleted = (new DateTime())->format('Y-m-d H:i:s');

        if (!$record->save()) {
            return false;
        }

        $test->dateDeleted = self::dbDate($record->dateDeleted);

        Craft::info("Split test '{$test->handle}' (ID: {$test->id}) moved to trash", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestTrashed($test);

        return true;
    }

    /**
     * Restore a soft-deleted test from trash
     */
    public function restoreTest(Test $test): bool
    {
        if (!$test->id || !$test->isTrashed()) {
            return false;
        }

        $record = TestRecord::findOne($test->id);

        if (!$record) {
            return false;
        }

        $record->dateDeleted = null;

        // A test that was running comes back paused, so restoring never
        // silently sends visitors to the variant again. Starting it again goes
        // through startTest(), which clears its pages from the page cache.
        if ($record->status === Test::STATUS_RUNNING) {
            $record->status = Test::STATUS_PAUSED;
        }

        if (!$record->save()) {
            return false;
        }

        $test->dateDeleted = null;
        $test->status = $record->status;

        Craft::info("Split test '{$test->handle}' (ID: {$test->id}) restored from trash", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestRestored($test);

        return true;
    }

    /**
     * Permanently delete a test (hard delete)
     */
    public function hardDeleteTest(Test $test): bool
    {
        if (!$test->id) {
            return false;
        }

        $record = TestRecord::findOne($test->id);

        if (!$record) {
            return false;
        }

        $result = (bool) $record->delete();

        if ($result) {
            Craft::info("Split test '{$test->handle}' (ID: {$test->id}) permanently deleted", 'abtestcraft');

            // Audit log
            ABTestCraft::getInstance()->audit->logTestDeleted($test);
        }

        return $result;
    }

    /**
     * Start a test
     *
     * @return bool|string Returns true on success, or error message string on failure
     */
    public function startTest(Test $test): bool|string
    {
        if (!$test->canStart()) {
            return 'Test cannot be started from its current status.';
        }

        if (!$test->hasEnabledGoals()) {
            return 'At least one conversion goal must be enabled before starting the test.';
        }

        if ($overlapping = $this->getOverlappingRunningTest($test)) {
            return $this->overlapMessage($overlapping);
        }

        $test->status = Test::STATUS_RUNNING;
        $test->startedAt = new DateTime();

        if (!$this->saveTest($test)) {
            return 'Failed to save test.';
        }

        Craft::info("Split test '{$test->handle}' started", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestStarted($test);

        return true;
    }

    /**
     * Pause a test
     */
    public function pauseTest(Test $test): bool
    {
        if (!$test->canPause()) {
            return false;
        }

        $test->status = Test::STATUS_PAUSED;

        if (!$this->saveTest($test)) {
            return false;
        }

        Craft::info("Split test '{$test->handle}' paused", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestPaused($test);

        return true;
    }

    /**
     * Complete a test with a winner
     */
    public function completeTest(Test $test, ?string $winner = null): bool
    {
        $test->status = Test::STATUS_COMPLETED;
        $test->endedAt = new DateTime();
        $test->winnerVariant = $winner;

        if (!$this->saveTest($test)) {
            return false;
        }

        Craft::info("Split test '{$test->handle}' completed. Winner: " . ($winner ?? 'none'), 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestCompleted($test);

        return true;
    }

    /**
     * A date read from the database. Craft stores dates in UTC; parsing them
     * with `new DateTime()` read them as the site's local time, so tests looked
     * hours younger or older than they were (and dates showed shifted).
     */
    private static function dbDate(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }
        if (!$value) {
            return null;
        }

        return DateTimeHelper::toDateTime($value) ?: null;
    }

    /**
     * Create model from record
     */
    private function createModelFromRecord(TestRecord $record): Test
    {
        $test = new Test();
        // Cast integer columns: some DB drivers return them as strings, and
        // assigning a string to an ?int property throws under strict_types
        $test->id = (int) $record->id;
        $test->siteId = $record->siteId !== null ? (int) $record->siteId : null;
        $test->name = $record->name;
        $test->handle = $record->handle;
        $test->hypothesis = $record->hypothesis;
        $test->variantDescription = $record->variantDescription;
        $test->learnings = $record->learnings;
        $test->status = $record->status;
        $test->controlEntryId = $record->controlEntryId !== null ? (int) $record->controlEntryId : null;
        $test->variantEntryId = $record->variantEntryId !== null ? (int) $record->variantEntryId : null;
        $test->trafficSplit = (int) $record->trafficSplit;
        $test->goalType = $record->goalType;
        $test->goalValue = $record->goalValue;
        $test->primaryGoalType = $record->primaryGoalType ?: null;
        $test->startedAt = self::dbDate($record->startedAt);
        $test->endedAt = self::dbDate($record->endedAt);
        $test->winnerVariant = $record->winnerVariant;
        // Needed by NotificationService's cooldown; without it every conversion
        // on a significant test sent another significance email
        $test->significanceNotifiedAt = self::dbDate($record->significanceNotifiedAt);
        $test->dateCreated = self::dbDate($record->dateCreated);
        $test->dateUpdated = self::dbDate($record->dateUpdated);
        $test->dateDeleted = self::dbDate($record->dateDeleted);
        $test->uid = $record->uid;

        return $test;
    }
}
