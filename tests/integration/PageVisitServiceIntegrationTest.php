<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Settings;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;
use livehand\abtestcraft\services\PageVisitService;

/**
 * Page-visit goals counted from the beacon on every page
 */
class PageVisitServiceIntegrationTest extends Unit
{
    private PageVisitService $service;

    /** @var int[] */
    private array $testIds = [];

    private string $visitorId;

    private string $originalMode;

    protected function _before(): void
    {
        $this->service = ABTestCraft::getInstance()->pageVisits;
        $this->visitorId = StringHelper::UUID();
        $_COOKIE['_abtestcraft_vid'] = $this->visitorId;

        $settings = ABTestCraft::getInstance()->getSettings();
        $this->originalMode = $settings->conversionCountingMode;
        $settings->conversionCountingMode = Settings::COUNTING_PER_GOAL_TYPE;
    }

    protected function _after(): void
    {
        foreach ($this->testIds as $id) {
            DailyStatsRecord::deleteAll(['testId' => $id]);
            VisitorRecord::deleteAll(['testId' => $id]);
            GoalRecord::deleteAll(['testId' => $id]);
            TestRecord::deleteAll(['id' => $id]);
        }
        $this->testIds = [];
        unset($_COOKIE['_abtestcraft_vid']);
        ABTestCraft::getInstance()->getSettings()->conversionCountingMode = $this->originalMode;
    }

    public function testMatchingPageIsCountedForTheVisitorsVariant(): void
    {
        $testId = $this->createTest('/thank-you');
        $this->assignVisitor($testId, Test::VARIANT_VARIANT);

        $counted = $this->service->recordPageVisit('https://example.com/thank-you?from=form');

        $this->assertSame([['testHandle' => $this->handle($testId), 'variant' => 'variant']], $counted);
        $this->assertSame(1, $this->conversions($testId, 'variant'));
    }

    public function testOtherPagesAreNotCounted(): void
    {
        $testId = $this->createTest('/thank-you');
        $this->assignVisitor($testId);

        $this->assertSame([], $this->service->recordPageVisit('https://example.com/contact'));
        $this->assertSame(0, $this->conversions($testId));
    }

    public function testOnlyRunningTestsWithAnEnabledGoalCount(): void
    {
        $paused = $this->createTest('/thank-you', Test::STATUS_PAUSED);
        $disabled = $this->createTest('/thank-you', Test::STATUS_RUNNING, false);
        $this->assignVisitor($paused);
        $this->assignVisitor($disabled);

        $this->assertSame([], $this->service->recordPageVisit('https://example.com/thank-you'));
    }

    public function testUnassignedVisitorCountsNothing(): void
    {
        $assigned = $this->createTest('/thank-you');
        $this->assignVisitor($assigned);

        $_COOKIE['_abtestcraft_vid'] = StringHelper::UUID(); // a different visitor
        $this->assertSame([], $this->service->recordPageVisit('https://example.com/thank-you'));

        unset($_COOKIE['_abtestcraft_vid']);
        $this->assertSame([], $this->service->recordPageVisit('https://example.com/thank-you'));
        $this->assertSame(0, $this->conversions($assigned));
    }

    public function testRepeatVisitsCountOnceInPerGoalTypeMode(): void
    {
        $testId = $this->createTest('/thank-you');
        $this->assignVisitor($testId);

        $this->service->recordPageVisit('https://example.com/thank-you');
        $this->service->recordPageVisit('https://example.com/thank-you');
        $this->assertSame(1, $this->conversions($testId));
    }

    public function testBeaconScriptIsTheSameForEveryVisitor(): void
    {
        $script = $this->service->getBeaconScript();
        unset($_COOKIE['_abtestcraft_vid']);

        $this->assertSame($script, $this->service->getBeaconScript());
        $this->assertStringContainsString('_abtestcraft_on=1', $script);
        $this->assertStringContainsString('abtestcraft', $script);
        $this->assertStringNotContainsString('http', $script, 'Endpoint is root-relative');
    }

    // ========================================
    // Helpers
    // ========================================

    private function createTest(string $pageUrl, string $status = Test::STATUS_RUNNING, bool $goalEnabled = true): int
    {
        $entryIds = (new Query())->select(['id'])->from('{{%elements}}')->limit(2)->column();

        $record = new TestRecord();
        $record->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $record->name = 'Page visit test';
        $record->handle = 'pageVisitTest' . StringHelper::randomString(8);
        $record->status = $status;
        $record->controlEntryId = (int) $entryIds[0];
        $record->variantEntryId = (int) $entryIds[1];
        $record->trafficSplit = 50;
        $record->goalType = Goal::TYPE_PAGE;
        $record->save(false);
        $this->testIds[] = (int) $record->id;

        $goal = new GoalRecord();
        $goal->testId = $record->id;
        $goal->goalType = Goal::TYPE_PAGE;
        $goal->isEnabled = $goalEnabled;
        $goal->config = ['pageUrl' => $pageUrl, 'matchType' => Goal::MATCH_EXACT];
        $goal->sortOrder = 0;
        $goal->save(false);

        return (int) $record->id;
    }

    private function assignVisitor(int $testId, string $variant = Test::VARIANT_CONTROL): void
    {
        $visitor = new VisitorRecord();
        $visitor->testId = $testId;
        $visitor->visitorId = $this->visitorId;
        $visitor->variant = $variant;
        $visitor->converted = false;
        $visitor->save(false);
    }

    private function conversions(int $testId, ?string $variant = null): int
    {
        $query = (new Query())
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $testId, 'goalType' => Goal::TYPE_PAGE]);
        if ($variant !== null) {
            $query->andWhere(['variant' => $variant]);
        }

        return (int) $query->sum('conversions');
    }

    private function handle(int $testId): string
    {
        return (string) TestRecord::findOne($testId)->handle;
    }
}
