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
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\services\ConsentService;

/**
 * 1.3.0: the safety guardrail, "When a winner is found", the winner email,
 * scheduling, serving the winner, consent mode and the weekly summary
 */
class AgencyWorkflowIntegrationTest extends Unit
{
    /** @var int[] */
    private array $testIds = [];

    private array $originalSettings = [];
    private array $originalCookies = [];

    protected function _before(): void
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        foreach ([
            'minimumTestDays', 'significanceThreshold', 'minimumDetectableEffect', 'notificationEmails',
            'sendSignificanceEmail', 'requireConsent', 'consentModeSignal', 'consentCookie',
            'consentCookieName', 'consentCookieValue',
        ] as $name) {
            $this->originalSettings[$name] = $settings->$name;
        }
        $settings->minimumTestDays = 7;
        $settings->significanceThreshold = 0.95;
        $settings->minimumDetectableEffect = 0.25;
        $settings->notificationEmails = '';
        $this->originalCookies = $_COOKIE;
    }

    protected function _after(): void
    {
        foreach ($this->testIds as $id) {
            DailyStatsRecord::deleteAll(['testId' => $id]);
            GoalRecord::deleteAll(['testId' => $id]);
            TestRecord::deleteAll(['id' => $id]);
        }
        $this->testIds = [];
        $_COOKIE = $this->originalCookies;

        $settings = ABTestCraft::getInstance()->getSettings();
        foreach ($this->originalSettings as $name => $value) {
            $settings->$name = $value;
        }
    }

    // ========================================
    // Safety guardrail
    // ========================================

    public function testGuardrailPausesATestWithAClearLargeDrop(): void
    {
        $test = $this->createTest(['guardrail' => ['enabled' => true]]);
        $this->addStats($test, 2000, 200, 2000, 120); // 10% vs 6%

        $result = ABTestCraft::getInstance()->rules->checkTest($test);

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertStringContainsString('paused by its safety guardrail', (string) $result);
        $this->assertSame(Test::STATUS_PAUSED, $test->status);
        $this->assertNotNull($test->guardrailTriggeredAt);
        $this->assertStringContainsString('40% worse', (string) $test->guardrailMessage);
        $this->assertSame('triggered', ABTestCraft::getInstance()->stats->getSummaryStats($test)['guardrail']['status']);

        // Starting again watches afresh
        $this->assertTrue(ABTestCraft::getInstance()->tests->startTest($test));
        $this->assertNull($test->guardrailTriggeredAt);
    }

    public function testGuardrailCanEndTheTestInstead(): void
    {
        $test = $this->createTest(['guardrail' => ['enabled' => true, 'action' => Test::GUARDRAIL_END]]);
        $this->addStats($test, 2000, 200, 2000, 120);

        ABTestCraft::getInstance()->rules->checkTest($test);

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::STATUS_COMPLETED, $test->status);
        $this->assertSame(Test::VARIANT_CONTROL, $test->winnerVariant);
        $this->assertFalse($test->isServingWinner());
    }

    public function testGuardrailWaitsForEnoughData(): void
    {
        $test = $this->createTest(['guardrail' => ['enabled' => true]]);
        $this->addStats($test, 200, 30, 200, 10); // A huge drop, but only 200 visitors

        $this->assertNull(ABTestCraft::getInstance()->rules->checkTest($test));
        $this->assertSame(Test::STATUS_RUNNING, ABTestCraft::getInstance()->tests->getTestById($test->id)->status);
    }

    public function testGuardrailOffByDefault(): void
    {
        $test = $this->createTest();
        $this->addStats($test, 2000, 200, 2000, 120);

        ABTestCraft::getInstance()->rules->checkTest($test);
        $this->assertSame(Test::STATUS_RUNNING, ABTestCraft::getInstance()->tests->getTestById($test->id)->status);
    }

    public function testGuardrailValidation(): void
    {
        $test = new Test(['guardrail' => ['enabled' => true, 'drop' => 2, 'confidence' => 0.8, 'minVisitors' => 10]]);
        $test->validate(['guardrail']);
        $this->assertCount(3, $test->getErrors('guardrail'));

        $test = new Test(['guardrail' => ['enabled' => false, 'drop' => 2]]);
        $this->assertTrue($test->validate(['guardrail']), 'Not validated when off');
    }

    // ========================================
    // When a winner is found
    // ========================================

    public function testWinnerFoundEndsAndServesTheVariant(): void
    {
        $test = $this->createTest(['whenWinnerFound' => Test::WINNER_SERVE]);
        $this->addStats($test, 3000, 300, 3000, 390); // 10% vs 13%, ready

        ABTestCraft::getInstance()->rules->checkTest($test);

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::STATUS_COMPLETED, $test->status);
        $this->assertSame(Test::VARIANT_VARIANT, $test->winnerVariant);
        $this->assertTrue($test->isServingWinner());
        $this->assertSame($test->id, ABTestCraft::getInstance()->tests
            ->getServingTestByControlEntryId($test->controlEntryId, $test->siteId)?->id);
    }

    public function testWinnerFoundEndsWithoutServingAWinningControl(): void
    {
        $test = $this->createTest(['whenWinnerFound' => Test::WINNER_SERVE]);
        $this->addStats($test, 3000, 390, 3000, 300);

        ABTestCraft::getInstance()->rules->checkTest($test);

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::VARIANT_CONTROL, $test->winnerVariant);
        $this->assertFalse($test->isServingWinner(), 'The control already is the page');
    }

    public function testNotifyKeepsTheTestRunningAndEmailsOnce(): void
    {
        ABTestCraft::getInstance()->getSettings()->notificationEmails = 'qa@example.com';
        ABTestCraft::getInstance()->getSettings()->sendSignificanceEmail = true;
        $test = $this->createTest();
        $this->addStats($test, 3000, 300, 3000, 390);

        ABTestCraft::getInstance()->rules->checkTest($test);
        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::STATUS_RUNNING, $test->status);
        $firstSent = $test->significanceNotifiedAt;
        $this->assertNotNull($firstSent, 'Winner email sent');

        // Never again for this test (it used to repeat every hour)
        TestRecord::updateAll(
            ['significanceNotifiedAt' => Db::prepareDateForDb(new DateTime('-2 days'))],
            ['id' => $test->id]
        );
        ABTestCraft::getInstance()->rules->checkTest($test);
        $after = ABTestCraft::getInstance()->tests->getTestById($test->id)->significanceNotifiedAt;
        $this->assertLessThan(new DateTime('-1 day'), $after);
    }

    public function testNoAutoStopBeforeTheResultCanBeCalled(): void
    {
        $test = $this->createTest(['whenWinnerFound' => Test::WINNER_SERVE, 'startedAt' => '-2 days']);
        $this->addStats($test, 3000, 300, 3000, 390);

        ABTestCraft::getInstance()->rules->checkTest($test);
        $this->assertSame(Test::STATUS_RUNNING, ABTestCraft::getInstance()->tests->getTestById($test->id)->status);
    }

    // ========================================
    // Scheduling
    // ========================================

    public function testScheduledStart(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_DRAFT, 'startedAt' => null, 'scheduledStartAt' => '-1 minute']);

        $log = ABTestCraft::getInstance()->rules->runSchedules();

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::STATUS_RUNNING, $test->status);
        $this->assertNull($test->scheduledStartAt);
        $this->assertContains("'{$test->name}' started on schedule.", $log);
    }

    public function testFutureScheduleWaits(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_DRAFT, 'startedAt' => null, 'scheduledStartAt' => '+1 day']);
        ABTestCraft::getInstance()->rules->runSchedules();
        $this->assertSame(Test::STATUS_DRAFT, ABTestCraft::getInstance()->tests->getTestById($test->id)->status);
    }

    public function testScheduledStartThatCantHappenIsCleared(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_DRAFT, 'startedAt' => null, 'scheduledStartAt' => '-1 minute', 'goals' => false]);

        ABTestCraft::getInstance()->rules->runSchedules();

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::STATUS_DRAFT, $test->status);
        $this->assertNull($test->scheduledStartAt, "Cleared, so it isn't retried every minute");
    }

    public function testScheduledEndRecordsAWinnerAndServesIt(): void
    {
        $test = $this->createTest(['scheduledEndAt' => '-1 minute', 'whenWinnerFound' => Test::WINNER_SERVE]);
        $this->addStats($test, 3000, 300, 3000, 390);

        ABTestCraft::getInstance()->rules->runSchedules();

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::STATUS_COMPLETED, $test->status);
        $this->assertSame(Test::VARIANT_VARIANT, $test->winnerVariant);
        $this->assertTrue($test->isServingWinner());
    }

    public function testScheduledEndWithoutACallableResult(): void
    {
        $test = $this->createTest(['scheduledEndAt' => '-1 minute', 'startedAt' => '-2 days']);
        $this->addStats($test, 300, 30, 300, 39);

        ABTestCraft::getInstance()->rules->runSchedules();

        $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
        $this->assertSame(Test::STATUS_COMPLETED, $test->status);
        $this->assertNull($test->winnerVariant);
    }

    public function testScheduleValidation(): void
    {
        $test = new Test(['scheduledStartAt' => new DateTime('+2 days'), 'scheduledEndAt' => new DateTime('+1 day')]);
        $test->validate(['scheduledEndAt']);
        $this->assertNotEmpty($test->getErrors('scheduledEndAt'));
    }

    // ========================================
    // Serving the winner
    // ========================================

    public function testServingAWinnerBlocksAnotherTestOnThePage(): void
    {
        $serving = $this->createTest(['status' => Test::STATUS_COMPLETED, 'winnerVariant' => Test::VARIANT_VARIANT, 'serveWinner' => true]);
        $next = $this->createTest(['status' => Test::STATUS_DRAFT, 'controlEntryId' => $serving->controlEntryId]);

        $this->assertStringContainsString('showing the winner', (string) ABTestCraft::getInstance()->tests->startTest($next));

        $this->assertTrue(ABTestCraft::getInstance()->tests->setServeWinner($serving, false));
        $this->assertNull(ABTestCraft::getInstance()->tests->getServingTestByControlEntryId($serving->controlEntryId, $serving->siteId));
        $this->assertTrue(ABTestCraft::getInstance()->tests->startTest($next));
    }

    public function testOnlyAWinningVariantCanBeServed(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_COMPLETED, 'winnerVariant' => Test::VARIANT_CONTROL]);
        $this->assertIsString(ABTestCraft::getInstance()->tests->setServeWinner($test, true));
    }

    // ========================================
    // Consent
    // ========================================

    public function testConsentSources(): void
    {
        $consent = ABTestCraft::getInstance()->consent;
        $settings = ABTestCraft::getInstance()->getSettings();
        $_COOKIE = [];

        $settings->requireConsent = false;
        $this->assertTrue($consent->hasConsent(), 'Not required');

        $settings->requireConsent = true;
        $settings->consentCookie = 'auto';
        $this->assertFalse($consent->hasConsent(), 'No signal yet');

        $_COOKIE = [ConsentService::CONSENT_COOKIE => '1'];
        $this->assertTrue($consent->hasConsent(), 'Google Consent Mode / setConsent(true)');

        $_COOKIE = ['CookieConsent' => "{stamp:'x',necessary:true,preferences:false,statistics:true,marketing:false}"];
        $this->assertTrue($consent->hasConsent(), 'Cookiebot');
        $_COOKIE = ['CookieConsent' => "{stamp:'x',necessary:true,statistics:false}"];
        $this->assertFalse($consent->hasConsent(), 'Cookiebot, declined');

        $_COOKIE = ['cookieyes-consent' => 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes'];
        $this->assertTrue($consent->hasConsent(), 'CookieYes');
        $_COOKIE = ['cmplz_statistics' => 'allow'];
        $this->assertTrue($consent->hasConsent(), 'Complianz');
        $_COOKIE = ['OptanonConsent' => 'isGpcEnabled=0&groups=C0001%3A1%2CC0002%3A1%2CC0004%3A0'];
        $this->assertTrue($consent->hasConsent(), 'OneTrust');

        // An explicit no wins over a stale banner cookie
        $_COOKIE = ['cmplz_statistics' => 'allow', ConsentService::CONSENT_COOKIE => '0'];
        $this->assertFalse($consent->hasConsent(), 'Withdrawn');

        $settings->consentCookie = 'custom';
        $settings->consentCookieName = 'my_consent';
        $settings->consentCookieValue = 'stats=1';
        $_COOKIE = ['my_consent' => 'ads=0;stats=1'];
        $this->assertTrue($consent->hasConsent(), 'Custom');

        $settings->consentCookie = 'none';
        $_COOKIE = ['cmplz_statistics' => 'allow'];
        $this->assertFalse($consent->hasConsent(), 'Banner cookies not used');
    }

    public function testConsentScript(): void
    {
        $consent = ABTestCraft::getInstance()->consent;
        $settings = ABTestCraft::getInstance()->getSettings();

        $settings->requireConsent = false;
        $this->assertSame('', $consent->getConsentScript());

        $settings->requireConsent = true;
        $settings->consentModeSignal = 'analytics_storage';
        $script = $consent->getConsentScript();
        $this->assertStringContainsString('"analytics_storage"', $script);
        $this->assertStringContainsString('_abtestcraft_consent', $script);
        $this->assertStringContainsString('setConsent', $script);
    }

    public function testVisitorsWithoutConsentAreExcluded(): void
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        $settings->requireConsent = true;
        $settings->consentCookie = 'none';
        $_COOKIE = [];

        $filter = new \livehand\abtestcraft\services\VisitorFilterService();
        $this->assertTrue($filter->shouldExcludeRequest());
    }

    // ========================================
    // Weekly summary
    // ========================================

    public function testWeeklySummaryListsTests(): void
    {
        $running = $this->createTest(['name' => 'Summary running test']);
        $this->addStats($running, 500, 50, 500, 60);
        $this->createTest(['name' => 'Summary ended test', 'status' => Test::STATUS_COMPLETED, 'winnerVariant' => Test::VARIANT_VARIANT, 'endedAt' => '-1 day']);

        $summary = (string) ABTestCraft::getInstance()->notifications->buildWeeklySummary();

        $this->assertStringContainsString('Summary running test (running, day 10)', $summary);
        $this->assertStringContainsString('Control 10% of 500 visitors', $summary);
        $this->assertStringContainsString('Summary ended test: Variant won', $summary);
    }

    /**
     * Monday mornings (site time), once that day, only when switched on and
     * an address is set
     */
    public function testWeeklySummaryTiming(): void
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        $notifications = ABTestCraft::getInstance()->notifications;
        $state = ABTestCraft::getInstance()->state;
        $zone = new \DateTimeZone(Craft::$app->getTimeZone());
        $originalWeekly = $settings->sendWeeklySummary;
        $originalState = $state->get('weeklySummarySentOn');

        $this->createTest(['name' => 'Weekly timing test']);
        $monday9 = new DateTime('2030-01-07 09:00', $zone); // a Monday
        $monday7 = new DateTime('2030-01-07 07:59', $zone);
        $tuesday = new DateTime('2030-01-08 09:00', $zone);

        try {
            $settings->sendWeeklySummary = false;
            $settings->notificationEmails = 'qa@example.com';
            $state->set('weeklySummarySentOn', '2000-01-01');
            $this->assertFalse($notifications->sendWeeklySummaryIfDue($monday9), 'Off by default');

            $settings->sendWeeklySummary = true;
            $settings->notificationEmails = '';
            $this->assertFalse($notifications->sendWeeklySummaryIfDue($monday9), 'No address');

            $settings->notificationEmails = 'qa@example.com';
            $this->assertFalse($notifications->sendWeeklySummaryIfDue($monday7), 'Before 8am');
            $this->assertFalse($notifications->sendWeeklySummaryIfDue($tuesday), 'Not Monday');
            $this->assertTrue($notifications->sendWeeklySummaryIfDue($monday9), 'Monday 9am');
            $this->assertSame('2030-01-07', $state->get('weeklySummarySentOn'));
            $this->assertFalse($notifications->sendWeeklySummaryIfDue($monday9), 'Once that day');
        } finally {
            $settings->sendWeeklySummary = $originalWeekly;
            $state->set('weeklySummarySentOn', $originalState ?? '');
        }
    }

    /**
     * Child pages of a page showing a winner find that test
     */
    public function testChildPagesOfAServedWinner(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_COMPLETED, 'winnerVariant' => Test::VARIANT_VARIANT, 'serveWinner' => true]);
        $childId = (int) (new Query())->select(['id'])->from('{{%entries}}')->orderBy(['id' => SORT_ASC])->offset(2)->limit(1)->scalar();
        Craft::$app->getDb()->createCommand()->insert('{{%abtestcraft_test_descendants}}', [
            'testId' => $test->id,
            'controlEntryId' => $test->controlEntryId,
            'descendantEntryId' => $childId,
            'variantAncestorId' => $test->variantEntryId,
            'depth' => 1,
            'siteId' => $test->siteId,
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        $cascade = ABTestCraft::getInstance()->cascade;
        try {
            $this->assertNull($cascade->getCascadeInfo($childId, $test->siteId), 'Not a running test');
            $info = $cascade->getCascadeInfo($childId, $test->siteId, true);
            $this->assertSame($test->id, $info['testId'] ?? null);
            $this->assertSame($test->variantEntryId, $info['variantAncestorId'] ?? null);

            ABTestCraft::getInstance()->tests->setServeWinner($test, false);
            $this->assertNull($cascade->getCascadeInfo($childId, $test->siteId, true), 'Stopped showing it');
        } finally {
            Craft::$app->getDb()->createCommand()->delete('{{%abtestcraft_test_descendants}}', ['testId' => $test->id])->execute();
        }
    }

    // ========================================
    // Helpers
    // ========================================

    private function createTest(array $attributes = []): Test
    {
        [$controlId, $variantId] = array_map('intval', (new Query())
            ->select(['id'])->from('{{%entries}}')->orderBy(['id' => SORT_ASC])->limit(2)->column());

        $record = new TestRecord();
        $record->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $record->name = $attributes['name'] ?? 'Agency workflow test';
        $record->handle = 'agency' . StringHelper::randomString(8);
        $record->status = $attributes['status'] ?? Test::STATUS_RUNNING;
        $record->controlEntryId = $attributes['controlEntryId'] ?? $controlId;
        $record->variantEntryId = $variantId;
        $record->trafficSplit = 50;
        $record->goalType = Goal::TYPE_FORM;
        $record->whenWinnerFound = $attributes['whenWinnerFound'] ?? Test::WINNER_NOTIFY;
        $record->winnerVariant = $attributes['winnerVariant'] ?? null;
        $record->serveWinner = $attributes['serveWinner'] ?? false;
        $record->guardrail = $attributes['guardrail'] ?? null;
        foreach (['startedAt' => '-10 days', 'scheduledStartAt' => null, 'scheduledEndAt' => null, 'endedAt' => null] as $field => $default) {
            $value = array_key_exists($field, $attributes) ? $attributes[$field] : $default;
            $record->$field = $value !== null ? Db::prepareDateForDb(new DateTime($value)) : null;
        }
        $record->save(false);
        $this->testIds[] = (int) $record->id;

        if ($attributes['goals'] ?? true) {
            $goal = new GoalRecord();
            $goal->testId = $record->id;
            $goal->goalType = Goal::TYPE_PHONE;
            $goal->isEnabled = true;
            $goal->save(false);
        }

        return ABTestCraft::getInstance()->tests->getTestById((int) $record->id);
    }

    private function addStats(Test $test, int $controlVisitors, int $controlConverted, int $variantVisitors, int $variantConverted): void
    {
        foreach ([
            Test::VARIANT_CONTROL => [$controlVisitors, $controlConverted],
            Test::VARIANT_VARIANT => [$variantVisitors, $variantConverted],
        ] as $variant => [$visitors, $converted]) {
            $record = new DailyStatsRecord();
            $record->testId = $test->id;
            $record->date = (new DateTime())->format('Y-m-d');
            $record->variant = $variant;
            $record->goalType = null;
            $record->impressions = $visitors;
            $record->conversions = $converted;
            $record->visitors = $visitors;
            $record->convertedVisitors = $converted;
            $record->save(false);
        }
    }
}
