<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\migrations\m261126_000001_per_visitor_stats;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Settings;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;

/**
 * 1.2.0: per-visitor results, the peeking guard, sample ratio mismatch,
 * overlapping tests, bot filtering and the stats backfill
 */
class PerVisitorStatsIntegrationTest extends Unit
{
    /** @var int[] */
    private array $testIds = [];

    private array $originalSettings = [];

    protected function _before(): void
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        foreach (['minimumTestDays', 'significanceThreshold', 'minimumDetectableEffect', 'conversionCountingMode', 'excludeBots'] as $name) {
            $this->originalSettings[$name] = $settings->$name;
        }
        $settings->minimumTestDays = 7;
        $settings->significanceThreshold = 0.95;
        $settings->minimumDetectableEffect = 0.25;
    }

    protected function _after(): void
    {
        foreach ($this->testIds as $id) {
            DailyStatsRecord::deleteAll(['testId' => $id]);
            GoalRecord::deleteAll(['testId' => $id]);
            VisitorRecord::deleteAll(['testId' => $id]);
            Craft::$app->getDb()->createCommand()->delete('{{%abtestcraft_test_descendants}}', ['testId' => $id])->execute();
            TestRecord::deleteAll(['id' => $id]);
            ABTestCraft::getInstance()->stats->invalidateStatsCache($id);
        }
        $this->testIds = [];
        unset($_COOKIE['_abtestcraft_vid']);

        $settings = ABTestCraft::getInstance()->getSettings();
        foreach ($this->originalSettings as $name => $value) {
            $settings->$name = $value;
        }
    }

    // ========================================
    // Recording
    // ========================================

    /**
     * Whatever the counting mode, a visitor counts once per goal type and once
     * for "any goal"; the mode only changes the raw conversions total
     *
     * @dataProvider countingModes
     */
    public function testEachVisitorCountsOncePerGoalInEveryMode(string $mode, int $expectedConversions): void
    {
        ABTestCraft::getInstance()->getSettings()->conversionCountingMode = $mode;
        $test = $this->createTest();
        $visitorId = $this->assignVisitor($test, Test::VARIANT_VARIANT);
        $_COOKIE['_abtestcraft_vid'] = $visitorId;

        foreach (['phone', 'email', 'phone', 'email'] as $type) {
            $this->assertTrue(ABTestCraft::getInstance()->tracking->recordConversion($test, $type));
        }

        $this->assertSame(1, $this->sum($test, 'convertedVisitors', null));
        $this->assertSame(1, $this->sum($test, 'convertedVisitors', 'phone'));
        $this->assertSame(1, $this->sum($test, 'convertedVisitors', 'email'));
        $this->assertSame($expectedConversions, $this->sum($test, 'conversions', null));
    }

    public static function countingModes(): array
    {
        return [
            'per goal type' => [Settings::COUNTING_PER_GOAL_TYPE, 2],
            'first only' => [Settings::COUNTING_FIRST_ONLY, 1],
            'unlimited' => [Settings::COUNTING_UNLIMITED, 4],
        ];
    }

    public function testNewVisitorsAreCountedInDailyStats(): void
    {
        $test = $this->createTest();
        ABTestCraft::getInstance()->tracking->recordNewVisitor($test, Test::VARIANT_CONTROL);
        ABTestCraft::getInstance()->tracking->recordNewVisitor($test, Test::VARIANT_CONTROL);
        ABTestCraft::getInstance()->tracking->recordNewVisitor($test, Test::VARIANT_VARIANT);

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);
        $this->assertSame(['control' => 2, 'variant' => 1], $stats['visitors']);
    }

    // ========================================
    // Results
    // ========================================

    /**
     * 10% vs 13% of 3,000 visitors each is significant; the planned sample at
     * a 10% baseline and 25% MDE is about 2,500 per version
     */
    public function testResultIsCalledOnceSampleAndDurationAreReached(): void
    {
        $test = $this->createTest(['startedAt' => '-10 days']);
        $this->addStats($test, Test::VARIANT_CONTROL, visitors: 3000, converted: 300, pageViews: 9000);
        $this->addStats($test, Test::VARIANT_VARIANT, visitors: 3000, converted: 390, pageViews: 6000);

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        $this->assertSame(10.0, $stats['conversionRates']['control']);
        $this->assertSame(13.0, $stats['conversionRates']['variant']);
        $this->assertTrue($stats['sampleReached']);
        $this->assertTrue($stats['durationReached']);
        $this->assertTrue($stats['verdictReady']);
        $this->assertTrue($stats['isSignificant']);
        $this->assertSame(Test::VARIANT_VARIANT, $stats['winner']);
        $this->assertSame(['reached' => true, 'daysRemaining' => 0], $stats['timeEstimate']);
        // Page views don't affect the rate: the control's extra page views
        // used to drag its "conversion rate" down
        $this->assertSame(3.0, $stats['pagesPerVisitor']['control']);
        $this->assertSame(2.0, $stats['pagesPerVisitor']['variant']);
    }

    public function testSignificanceEmailUsesVisitorNumbers(): void
    {
        $test = $this->createTest(['startedAt' => '-10 days']);
        $this->addStats($test, Test::VARIANT_CONTROL, visitors: 3000, converted: 300);
        $this->addStats($test, Test::VARIANT_VARIANT, visitors: 3000, converted: 390);
        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        $body = (new \ReflectionMethod(ABTestCraft::getInstance()->notifications, 'buildSignificanceEmailBody'))
            ->invoke(ABTestCraft::getInstance()->notifications, $test, $stats, 'Variant');

        $this->assertStringContainsString('WINNER: Variant', $body);
        $this->assertStringContainsString('Primary goal: Any goal', $body);
        $this->assertStringContainsString('Control: 10% (300/3000)', $body);
        $this->assertStringContainsString('Variant: 13% (390/3000)', $body);
        $this->assertStringContainsString('has run for 10 days', $body);
        $this->assertStringContainsString("abtestcraft/tests/{$test->id}/results", $body);
    }

    public function testNoWinnerBeforeTheMinimumDuration(): void
    {
        $test = $this->createTest(['startedAt' => '-2 days']);
        $this->addStats($test, Test::VARIANT_CONTROL, visitors: 3000, converted: 300);
        $this->addStats($test, Test::VARIANT_VARIANT, visitors: 3000, converted: 390);

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        $this->assertGreaterThan(0.95, $stats['confidence'], 'Shown, but provisional');
        $this->assertFalse($stats['verdictReady']);
        $this->assertFalse($stats['isSignificant']);
        $this->assertNull($stats['winner']);
        $this->assertSame(5, $stats['timeEstimate']['daysRemaining']);
    }

    public function testNoWinnerBeforeThePlannedSample(): void
    {
        $test = $this->createTest(['startedAt' => '-10 days']);
        $this->addStats($test, Test::VARIANT_CONTROL, visitors: 500, converted: 40);
        $this->addStats($test, Test::VARIANT_VARIANT, visitors: 500, converted: 80);

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        $this->assertGreaterThan(0.99, $stats['confidence']);
        $this->assertFalse($stats['sampleReached']);
        $this->assertFalse($stats['isSignificant']);
        $this->assertLessThan(100, $stats['progressPercent']);
        $this->assertGreaterThan(0, $stats['timeEstimate']['daysRemaining']);
    }

    public function testPrimaryGoalDecidesTheRate(): void
    {
        $test = $this->createTest(['startedAt' => '-10 days']);
        $this->addStats($test, Test::VARIANT_CONTROL, visitors: 1000, converted: 120);
        $this->addStats($test, Test::VARIANT_VARIANT, visitors: 1000, converted: 150);
        $this->addStats($test, Test::VARIANT_CONTROL, converted: 50, goalType: Goal::TYPE_FORM);
        $this->addStats($test, Test::VARIANT_VARIANT, converted: 30, goalType: Goal::TYPE_FORM);

        $anyGoal = ABTestCraft::getInstance()->stats->getTestStats($test);
        $this->assertSame('Any goal', $anyGoal['primaryGoalLabel']);
        $this->assertSame(12.0, $anyGoal['conversionRates']['control']);
        $this->assertSame(15.0, $anyGoal['conversionRates']['variant']);

        $test->primaryGoalType = Goal::TYPE_FORM;
        $formGoal = ABTestCraft::getInstance()->stats->getTestStats($test);
        $this->assertSame(5.0, $formGoal['conversionRates']['control']);
        $this->assertSame(3.0, $formGoal['conversionRates']['variant']);
        $this->assertSame(['convertedVisitors' => 30, 'rate' => 3.0, 'conversions' => 0], $formGoal['goalStats']['form']['variant']);
    }

    public function testSampleRatioMismatchIsFlagged(): void
    {
        $test = $this->createTest(['startedAt' => '-3 days']);
        $this->addStats($test, Test::VARIANT_CONTROL, visitors: 600, converted: 30);
        $this->addStats($test, Test::VARIANT_VARIANT, visitors: 400, converted: 20);

        $srm = ABTestCraft::getInstance()->stats->getTestStats($test)['srm'];
        $this->assertTrue($srm['detected']);
        $this->assertSame(40.0, $srm['actualVariantPercent']);
        $this->assertSame(50, $srm['expectedVariantPercent']);

        // The same numbers match a 40% split
        $test->trafficSplit = 40;
        $this->assertFalse(ABTestCraft::getInstance()->stats->getTestStats($test)['srm']['detected']);
    }

    public function testBatchStatsMatchSingleTestStats(): void
    {
        $test = $this->createTest(['startedAt' => '-10 days']);
        $this->addStats($test, Test::VARIANT_CONTROL, visitors: 3000, converted: 300);
        $this->addStats($test, Test::VARIANT_VARIANT, visitors: 3000, converted: 390);

        $batch = ABTestCraft::getInstance()->stats->getBatchTestStats([$test])[$test->id];
        $single = ABTestCraft::getInstance()->stats->getTestStats($test);
        unset($single['dailyStats']);

        $this->assertSame($single, $batch);
        $this->assertSame(100, $batch['progressPercent'], 'The index page used to show 0%');
    }

    // ========================================
    // Overlapping tests
    // ========================================

    public function testTwoRunningTestsCantShareAPage(): void
    {
        $running = $this->createTest(['status' => Test::STATUS_RUNNING]);
        $second = $this->createTest(['status' => Test::STATUS_DRAFT, 'controlEntryId' => $running->controlEntryId]);
        $goal = new GoalRecord();
        $goal->testId = $second->id;
        $goal->goalType = Goal::TYPE_PHONE;
        $goal->isEnabled = true;
        $goal->save(false);

        $overlap = ABTestCraft::getInstance()->tests->getOverlappingRunningTest($second);
        $this->assertSame($running->id, $overlap?->id);
        $this->assertStringContainsString('already part of the running test', (string) ABTestCraft::getInstance()->tests->startTest($second));

        // Fine once the first test is paused
        ABTestCraft::getInstance()->tests->pauseTest($running);
        $this->assertNull(ABTestCraft::getInstance()->tests->getOverlappingRunningTest($second));
    }

    public function testAPageInsideARunningTestIsAnOverlap(): void
    {
        $running = $this->createTest(['status' => Test::STATUS_RUNNING]);
        [, , $childId] = $this->entryIds();
        Craft::$app->getDb()->createCommand()->insert('{{%abtestcraft_test_descendants}}', [
            'testId' => $running->id,
            'controlEntryId' => $running->controlEntryId,
            'descendantEntryId' => $childId,
            'variantAncestorId' => $running->variantEntryId,
            'depth' => 1,
            'siteId' => $running->siteId,
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        $child = $this->createTest(['status' => Test::STATUS_DRAFT, 'controlEntryId' => $childId]);
        $this->assertSame($running->id, ABTestCraft::getInstance()->tests->getOverlappingRunningTest($child)?->id);
    }

    // ========================================
    // Bots
    // ========================================

    public function testBotDetection(): void
    {
        $filter = ABTestCraft::getInstance()->visitorFilter;
        $bots = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36',
            'curl/8.4.0',
            'python-requests/2.31.0',
            'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)',
            '',
        ];
        foreach ($bots as $userAgent) {
            $this->assertTrue($filter->isBot($userAgent), "Bot: {$userAgent}");
        }

        $browsers = [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/440.0]',
            'Mozilla/5.0 (Linux; Android 11; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [Pinterest/iOS]',
        ];
        foreach ($browsers as $userAgent) {
            $this->assertFalse($filter->isBot($userAgent), "Browser: {$userAgent}");
        }

        ABTestCraft::getInstance()->getSettings()->excludeBots = false;
        $this->assertFalse($filter->isBot('curl/8.4.0'), 'Off when the setting is off');
    }

    // ========================================
    // Backfill
    // ========================================

    /**
     * The migration rebuilds visitor counts from the visitor records, and
     * fills in missing goal slots (first-only and unlimited modes didn't
     * record every goal type before 1.2.0)
     */
    public function testBackfillFromVisitorRecords(): void
    {
        $test = $this->createTest();
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        $insertVisitor = function (string $variant, ?string $conversionType) use ($test, $db, $now): string {
            $visitorId = StringHelper::UUID();
            $db->createCommand()->insert('{{%abtestcraft_visitors}}', [
                'testId' => $test->id,
                'visitorId' => $visitorId,
                'variant' => $variant,
                'converted' => $conversionType !== null,
                'conversionType' => $conversionType,
                'dateConverted' => $conversionType !== null ? $now : null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
            return $visitorId;
        };

        $insertVisitor(Test::VARIANT_CONTROL, null);
        $insertVisitor(Test::VARIANT_CONTROL, 'phone'); // no slots at all (unlimited mode)
        $perType = $insertVisitor(Test::VARIANT_VARIANT, 'email'); // per goal type: no '*' slot
        foreach (['phone', 'email'] as $type) {
            $db->createCommand()->insert('{{%abtestcraft_visitor_goals}}', [
                'testId' => $test->id,
                'visitorId' => $perType,
                'goalType' => $type,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $migration = new m261126_000001_per_visitor_stats();
        foreach (['utc' => 'UTC', 'siteZone' => Craft::$app->getTimeZone()] as $property => $zone) {
            $reflection = new \ReflectionProperty($migration, $property);
            $reflection->setValue($migration, new \DateTimeZone($zone));
        }
        (new \ReflectionMethod($migration, 'backfillTest'))->invoke($migration, (int) $test->id);

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);
        $this->assertSame(['control' => 2, 'variant' => 1], $stats['visitors']);
        $this->assertSame(['control' => 1, 'variant' => 1], $stats['convertedVisitors']);
        $this->assertSame(1, $stats['goalStats']['phone']['control']['convertedVisitors']);
        $this->assertSame(1, $stats['goalStats']['phone']['variant']['convertedVisitors']);
        $this->assertSame(1, $stats['goalStats']['email']['variant']['convertedVisitors']);

        // Every converted visitor now has a '*' slot and a slot for its type
        $slots = (new Query())->from('{{%abtestcraft_visitor_goals}}')->where(['testId' => $test->id])->count();
        $this->assertSame(5, (int) $slots);

        // Running it again changes nothing
        (new \ReflectionMethod($migration, 'backfillTest'))->invoke($migration, (int) $test->id);
        $this->assertSame(['control' => 2, 'variant' => 1], ABTestCraft::getInstance()->stats->getTestStats($test)['visitors']);
    }

    // ========================================
    // Helpers
    // ========================================

    private function createTest(array $attributes = []): Test
    {
        [$controlId, $variantId] = $this->entryIds();

        $record = new TestRecord();
        $record->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $record->name = 'Per visitor test';
        $record->handle = 'perVisitor' . StringHelper::randomString(8);
        $record->status = $attributes['status'] ?? Test::STATUS_RUNNING;
        $record->controlEntryId = $attributes['controlEntryId'] ?? $controlId;
        $record->variantEntryId = $variantId;
        $record->trafficSplit = 50;
        $record->goalType = Goal::TYPE_FORM;
        if (isset($attributes['startedAt'])) {
            $record->startedAt = Db::prepareDateForDb(new DateTime($attributes['startedAt']));
        }
        $record->save(false);
        $this->testIds[] = (int) $record->id;

        return ABTestCraft::getInstance()->tests->getTestById((int) $record->id);
    }

    /**
     * @return int[] Three distinct element IDs
     */
    private function entryIds(): array
    {
        return array_map('intval', (new Query())->select(['id'])->from('{{%entries}}')->orderBy(['id' => SORT_ASC])->limit(3)->column());
    }

    private function assignVisitor(Test $test, string $variant): string
    {
        $visitorId = StringHelper::UUID();
        $record = new VisitorRecord();
        $record->testId = $test->id;
        $record->visitorId = $visitorId;
        $record->variant = $variant;
        $record->converted = false;
        $record->save(false);
        ABTestCraft::getInstance()->tracking->recordNewVisitor($test, $variant);

        return $visitorId;
    }

    private function addStats(
        Test $test,
        string $variant,
        int $visitors = 0,
        int $converted = 0,
        int $pageViews = 0,
        ?string $goalType = null,
    ): void {
        $record = new DailyStatsRecord();
        $record->testId = $test->id;
        $record->date = (new DateTime())->format('Y-m-d');
        $record->variant = $variant;
        $record->goalType = $goalType;
        $record->impressions = $pageViews;
        $record->conversions = 0;
        $record->visitors = $visitors;
        $record->convertedVisitors = $converted;
        $record->save(false);
    }

    private function sum(Test $test, string $column, ?string $goalType): int
    {
        return (int) (new Query())
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id, 'goalType' => $goalType])
            ->sum("[[{$column}]]");
    }
}
