<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\services\GoalsService;
use livehand\abtestcraft\ABTestCraft;
use DateTime;

/**
 * Integration tests for GoalsService
 *
 * These tests verify goal CRUD operations with real database interactions.
 */
class GoalsServiceIntegrationTest extends Unit
{
    private GoalsService $service;
    private ?int $testRecordId = null;

    protected function _before(): void
    {
        $this->service = ABTestCraft::getInstance()->goals;
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
        if ($this->testRecordId) {
            GoalRecord::deleteAll(['testId' => $this->testRecordId]);
            TestRecord::deleteAll(['id' => $this->testRecordId]);
            $this->testRecordId = null;
        }

        // Also clean up by handle
        $testRecord = TestRecord::findOne(['handle' => 'goals-test']);
        if ($testRecord) {
            GoalRecord::deleteAll(['testId' => $testRecord->id]);
            $testRecord->delete();
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

        $record = new TestRecord();
        $record->siteId = $siteId;
        $record->name = $attributes['name'] ?? 'Goals Test';
        $record->handle = $attributes['handle'] ?? 'goals-test';
        $record->status = $attributes['status'] ?? Test::STATUS_DRAFT;
        $record->controlEntryId = $attributes['controlEntryId'] ?? $controlEntryId;
        $record->variantEntryId = $attributes['variantEntryId'] ?? $variantEntryId;
        $record->trafficSplit = $attributes['trafficSplit'] ?? 50;
        $record->goalType = $attributes['goalType'] ?? Goal::TYPE_PAGE;
        $record->save(false);

        $this->testRecordId = $record->id;

        return $record;
    }

    // ========================================
    // Save Goal Tests
    // ========================================

    /**
     * Test saving a new goal creates a record
     */
    public function testSaveGoalCreatesRecord(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_FORM;
        $goal->isEnabled = true;
        $goal->config = ['formSelector' => '.contact-form', 'successMethod' => Goal::SUCCESS_ANY];
        $goal->sortOrder = 0;

        $result = $this->service->saveGoal($goal);

        $this->assertTrue($result, 'saveGoal should return true');
        $this->assertNotNull($goal->id, 'Goal should have an ID after save');
        $this->assertNotNull($goal->dateCreated, 'Goal should have dateCreated');
        $this->assertNotNull($goal->uid, 'Goal should have a UID');

        // Verify record exists in database
        $record = GoalRecord::findOne($goal->id);
        $this->assertNotNull($record, 'GoalRecord should exist in database');
        $this->assertEquals(Goal::TYPE_FORM, $record->goalType);
        $this->assertTrue((bool)$record->isEnabled);
    }

    /**
     * Test saving updates an existing goal
     */
    public function testSaveGoalUpdatesExisting(): void
    {
        $testRecord = $this->createTestRecord();

        // Create initial goal
        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_PHONE;
        $goal->isEnabled = true;

        $this->service->saveGoal($goal);
        $originalId = $goal->id;

        // Update goal
        $goal->isEnabled = false;
        $result = $this->service->saveGoal($goal);

        $this->assertTrue($result, 'Update should succeed');
        $this->assertEquals($originalId, $goal->id, 'ID should not change');

        // Verify update
        $record = GoalRecord::findOne($goal->id);
        $this->assertFalse((bool)$record->isEnabled, 'isEnabled should be updated');
    }

    /**
     * Test validation failure returns false
     */
    public function testSaveGoalValidationFailure(): void
    {
        $goal = new Goal();
        // Missing required testId and goalType
        $goal->goalType = 'invalid-type';

        $result = $this->service->saveGoal($goal);

        $this->assertFalse($result, 'saveGoal should return false for invalid goal');
    }

    // ========================================
    // Get Goals Tests
    // ========================================

    /**
     * Test getGoalsByTestId returns all goals ordered by sortOrder
     */
    public function testGetGoalsByTestId(): void
    {
        $testRecord = $this->createTestRecord();

        // Create goals in reverse order
        $goal3 = new Goal();
        $goal3->testId = $testRecord->id;
        $goal3->goalType = Goal::TYPE_PAGE;
        $goal3->isEnabled = true;
        $goal3->sortOrder = 2;
        $this->service->saveGoal($goal3);

        $goal1 = new Goal();
        $goal1->testId = $testRecord->id;
        $goal1->goalType = Goal::TYPE_FORM;
        $goal1->isEnabled = true;
        $goal1->sortOrder = 0;
        $this->service->saveGoal($goal1);

        $goal2 = new Goal();
        $goal2->testId = $testRecord->id;
        $goal2->goalType = Goal::TYPE_PHONE;
        $goal2->isEnabled = false;
        $goal2->sortOrder = 1;
        $this->service->saveGoal($goal2);

        // Get goals
        $goals = $this->service->getGoalsByTestId($testRecord->id);

        $this->assertCount(3, $goals, 'Should return 3 goals');
        $this->assertEquals(Goal::TYPE_FORM, $goals[0]->goalType, 'First should be form (sortOrder 0)');
        $this->assertEquals(Goal::TYPE_PHONE, $goals[1]->goalType, 'Second should be phone (sortOrder 1)');
        $this->assertEquals(Goal::TYPE_PAGE, $goals[2]->goalType, 'Third should be page (sortOrder 2)');
    }

    /**
     * Test getEnabledGoalsByTestId returns only enabled goals
     */
    public function testGetEnabledGoalsByTestId(): void
    {
        $testRecord = $this->createTestRecord();

        // Create enabled goal
        $goal1 = new Goal();
        $goal1->testId = $testRecord->id;
        $goal1->goalType = Goal::TYPE_FORM;
        $goal1->isEnabled = true;
        $goal1->sortOrder = 0;
        $this->service->saveGoal($goal1);

        // Create disabled goal
        $goal2 = new Goal();
        $goal2->testId = $testRecord->id;
        $goal2->goalType = Goal::TYPE_PHONE;
        $goal2->isEnabled = false;
        $goal2->sortOrder = 1;
        $this->service->saveGoal($goal2);

        // Get enabled goals
        $goals = $this->service->getEnabledGoalsByTestId($testRecord->id);

        $this->assertCount(1, $goals, 'Should return only 1 enabled goal');
        $this->assertEquals(Goal::TYPE_FORM, $goals[0]->goalType);
        $this->assertTrue($goals[0]->isEnabled);
    }

    /**
     * Test getGoalById returns correct goal
     */
    public function testGetGoalById(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_DOWNLOAD;
        $goal->isEnabled = true;
        $goal->config = ['extensions' => ['pdf', 'doc']];
        $this->service->saveGoal($goal);

        $retrieved = $this->service->getGoalById($goal->id);

        $this->assertNotNull($retrieved, 'Goal should be found');
        $this->assertEquals($goal->id, $retrieved->id);
        $this->assertEquals(Goal::TYPE_DOWNLOAD, $retrieved->goalType);
        $this->assertEquals(['pdf', 'doc'], $retrieved->config['extensions']);
    }

    /**
     * Test getGoalById returns null for non-existent ID
     */
    public function testGetGoalByIdNotFound(): void
    {
        $retrieved = $this->service->getGoalById(999999);

        $this->assertNull($retrieved, 'Should return null for non-existent ID');
    }

    /**
     * Test getGoalByTestAndType returns correct goal
     */
    public function testGetGoalByTestAndType(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_EMAIL;
        $goal->isEnabled = true;
        $this->service->saveGoal($goal);

        $retrieved = $this->service->getGoalByTestAndType($testRecord->id, Goal::TYPE_EMAIL);

        $this->assertNotNull($retrieved, 'Goal should be found');
        $this->assertEquals(Goal::TYPE_EMAIL, $retrieved->goalType);
    }

    /**
     * Test getGoalByTestAndType returns null when not found
     */
    public function testGetGoalByTestAndTypeNotFound(): void
    {
        $testRecord = $this->createTestRecord();

        $retrieved = $this->service->getGoalByTestAndType($testRecord->id, Goal::TYPE_CUSTOM);

        $this->assertNull($retrieved, 'Should return null when goal type not found');
    }

    // ========================================
    // Save Goals For Test Tests
    // ========================================

    /**
     * Test saveGoalsForTest replaces existing goals
     */
    public function testSaveGoalsForTestReplacesExisting(): void
    {
        $testRecord = $this->createTestRecord();
        $test = new Test();
        $test->id = $testRecord->id;

        // Create existing goal
        $existingGoal = new Goal();
        $existingGoal->testId = $testRecord->id;
        $existingGoal->goalType = Goal::TYPE_PHONE;
        $existingGoal->isEnabled = true;
        $this->service->saveGoal($existingGoal);
        $oldGoalId = $existingGoal->id;

        // Save new goals (should replace)
        $goalsData = [
            ['goalType' => Goal::TYPE_FORM, 'isEnabled' => true, 'config' => ['formSelector' => 'form']],
            ['goalType' => Goal::TYPE_PAGE, 'isEnabled' => true, 'config' => ['pageUrl' => '/thank-you']],
        ];

        $result = $this->service->saveGoalsForTest($test, $goalsData);

        $this->assertTrue($result, 'saveGoalsForTest should succeed');

        // Verify old goal is deleted
        $oldGoal = $this->service->getGoalById($oldGoalId);
        $this->assertNull($oldGoal, 'Old goal should be deleted');

        // Verify new goals exist
        $goals = $this->service->getGoalsByTestId($testRecord->id);
        $this->assertCount(2, $goals, 'Should have 2 new goals');

        $types = array_map(fn($g) => $g->goalType, $goals);
        $this->assertContains(Goal::TYPE_FORM, $types);
        $this->assertContains(Goal::TYPE_PAGE, $types);
    }

    /**
     * Test saveGoalsForTest only saves enabled goals
     */
    public function testSaveGoalsForTestSkipsDisabled(): void
    {
        $testRecord = $this->createTestRecord();
        $test = new Test();
        $test->id = $testRecord->id;

        $goalsData = [
            ['goalType' => Goal::TYPE_FORM, 'isEnabled' => true],
            ['goalType' => Goal::TYPE_PHONE, 'isEnabled' => false],  // Should be skipped
            ['goalType' => Goal::TYPE_EMAIL, 'isEnabled' => true],
        ];

        $result = $this->service->saveGoalsForTest($test, $goalsData);

        $this->assertTrue($result);

        $goals = $this->service->getGoalsByTestId($testRecord->id);
        $this->assertCount(2, $goals, 'Should only have 2 enabled goals');
    }

    /**
     * Test saveGoalsForTest assigns correct sortOrder
     */
    public function testSaveGoalsForTestAssignsSortOrder(): void
    {
        $testRecord = $this->createTestRecord();
        $test = new Test();
        $test->id = $testRecord->id;

        $goalsData = [
            ['goalType' => Goal::TYPE_FORM, 'isEnabled' => true],
            ['goalType' => Goal::TYPE_PAGE, 'isEnabled' => true],
            ['goalType' => Goal::TYPE_DOWNLOAD, 'isEnabled' => true],
        ];

        $this->service->saveGoalsForTest($test, $goalsData);

        $goals = $this->service->getGoalsByTestId($testRecord->id);

        $this->assertEquals(0, $goals[0]->sortOrder);
        $this->assertEquals(1, $goals[1]->sortOrder);
        $this->assertEquals(2, $goals[2]->sortOrder);
    }

    // ========================================
    // Delete Goals Tests
    // ========================================

    /**
     * Test deleteGoal removes a single goal
     */
    public function testDeleteGoal(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_PHONE;
        $goal->isEnabled = true;
        $this->service->saveGoal($goal);

        $goalId = $goal->id;

        $result = $this->service->deleteGoal($goal);

        $this->assertTrue($result, 'deleteGoal should return true');

        $deleted = $this->service->getGoalById($goalId);
        $this->assertNull($deleted, 'Goal should no longer exist');
    }

    /**
     * Test deleteGoal returns false for goal without ID
     */
    public function testDeleteGoalWithoutId(): void
    {
        $goal = new Goal();
        $goal->goalType = Goal::TYPE_PHONE;

        $result = $this->service->deleteGoal($goal);

        $this->assertFalse($result, 'Should return false for goal without ID');
    }

    /**
     * Test deleteGoalsByTestId removes all goals
     */
    public function testDeleteGoalsByTestId(): void
    {
        $testRecord = $this->createTestRecord();

        // Create multiple goals with different goal types (unique constraint on testId+goalType)
        $goalTypes = [Goal::TYPE_PHONE, Goal::TYPE_EMAIL, Goal::TYPE_FORM];
        foreach ($goalTypes as $goalType) {
            $goal = new Goal();
            $goal->testId = $testRecord->id;
            $goal->goalType = $goalType;
            $goal->isEnabled = true;
            $this->service->saveGoal($goal);
        }

        $this->assertCount(3, $this->service->getGoalsByTestId($testRecord->id));

        $result = $this->service->deleteGoalsByTestId($testRecord->id);

        $this->assertTrue($result, 'deleteGoalsByTestId should return true');

        $goals = $this->service->getGoalsByTestId($testRecord->id);
        $this->assertCount(0, $goals, 'All goals should be deleted');
    }

    // ========================================
    // Goal Type Configuration Tests
    // ========================================

    /**
     * Test form goal configuration serialization
     */
    public function testFormGoalConfigSerialization(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_FORM;
        $goal->isEnabled = true;
        $goal->setFormConfig('.contact-form', Goal::SUCCESS_REDIRECT, '/thank-you');
        $this->service->saveGoal($goal);

        // Retrieve and verify
        $retrieved = $this->service->getGoalById($goal->id);

        $this->assertEquals('.contact-form', $retrieved->getFormSelector());
        $this->assertEquals(Goal::SUCCESS_REDIRECT, $retrieved->getSuccessMethod());
        $this->assertEquals('/thank-you', $retrieved->getSuccessSelector());
    }

    /**
     * Test download goal configuration serialization
     */
    public function testDownloadGoalConfigSerialization(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_DOWNLOAD;
        $goal->isEnabled = true;
        $goal->setDownloadConfig(['pdf', 'doc', 'zip']);
        $this->service->saveGoal($goal);

        // Retrieve and verify
        $retrieved = $this->service->getGoalById($goal->id);

        $this->assertEquals(['pdf', 'doc', 'zip'], $retrieved->getFileExtensions());
    }

    /**
     * Test page goal configuration serialization
     */
    public function testPageGoalConfigSerialization(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_PAGE;
        $goal->isEnabled = true;
        $goal->setPageConfig('/checkout/complete', Goal::MATCH_STARTS_WITH);
        $this->service->saveGoal($goal);

        // Retrieve and verify
        $retrieved = $this->service->getGoalById($goal->id);

        $this->assertEquals('/checkout/complete', $retrieved->getPageUrl());
        $this->assertEquals(Goal::MATCH_STARTS_WITH, $retrieved->getMatchType());
    }

    /**
     * Test custom event goal configuration serialization
     */
    public function testCustomGoalConfigSerialization(): void
    {
        $testRecord = $this->createTestRecord();

        $goal = new Goal();
        $goal->testId = $testRecord->id;
        $goal->goalType = Goal::TYPE_CUSTOM;
        $goal->isEnabled = true;
        $goal->setCustomConfig('purchase_complete, add_to_cart');
        $this->service->saveGoal($goal);

        // Retrieve and verify
        $retrieved = $this->service->getGoalById($goal->id);

        $this->assertEquals('purchase_complete, add_to_cart', $retrieved->getEventName());
        $this->assertEquals(['purchase_complete', 'add_to_cart'], $retrieved->getEventNames());
    }

    // ========================================
    // Get Goals JS Config Tests
    // ========================================

    /**
     * Test getGoalsJsConfig returns correct format
     */
    public function testGetGoalsJsConfig(): void
    {
        $testRecord = $this->createTestRecord();

        // Create goals
        $formGoal = new Goal();
        $formGoal->testId = $testRecord->id;
        $formGoal->goalType = Goal::TYPE_FORM;
        $formGoal->isEnabled = true;
        $formGoal->setFormConfig('form.contact', Goal::SUCCESS_ANY, null);
        $this->service->saveGoal($formGoal);

        $pageGoal = new Goal();
        $pageGoal->testId = $testRecord->id;
        $pageGoal->goalType = Goal::TYPE_PAGE;
        $pageGoal->isEnabled = true;
        $pageGoal->setPageConfig('/thanks', Goal::MATCH_EXACT);
        $this->service->saveGoal($pageGoal);

        // Disabled goal - should not appear
        $disabledGoal = new Goal();
        $disabledGoal->testId = $testRecord->id;
        $disabledGoal->goalType = Goal::TYPE_PHONE;
        $disabledGoal->isEnabled = false;
        $this->service->saveGoal($disabledGoal);

        // Get JS config
        $config = $this->service->getGoalsJsConfig($testRecord->id);

        $this->assertArrayHasKey(Goal::TYPE_FORM, $config, 'Should have form goal');
        $this->assertArrayHasKey(Goal::TYPE_PAGE, $config, 'Should have page goal');
        $this->assertArrayNotHasKey(Goal::TYPE_PHONE, $config, 'Should not have disabled phone goal');

        // Verify structure
        $formConfig = $config[Goal::TYPE_FORM];
        $this->assertArrayHasKey('id', $formConfig);
        $this->assertArrayHasKey('type', $formConfig);
        $this->assertArrayHasKey('enabled', $formConfig);
        $this->assertArrayHasKey('config', $formConfig);
        $this->assertEquals(Goal::TYPE_FORM, $formConfig['type']);
        $this->assertTrue($formConfig['enabled']);
    }

    // ========================================
    // Empty Results Tests
    // ========================================

    /**
     * Test getGoalsByTestId returns empty array for test with no goals
     */
    public function testGetGoalsByTestIdReturnsEmptyArray(): void
    {
        $testRecord = $this->createTestRecord();

        $goals = $this->service->getGoalsByTestId($testRecord->id);

        $this->assertIsArray($goals);
        $this->assertCount(0, $goals);
    }

    /**
     * Test getGoalsJsConfig returns empty array for test with no goals
     */
    public function testGetGoalsJsConfigReturnsEmptyArray(): void
    {
        $testRecord = $this->createTestRecord();

        $config = $this->service->getGoalsJsConfig($testRecord->id);

        $this->assertIsArray($config);
        $this->assertCount(0, $config);
    }
}
