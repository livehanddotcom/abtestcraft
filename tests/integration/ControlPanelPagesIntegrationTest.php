<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\controllers\PluginsController;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\View;
use DateTime;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\controllers\TestsController;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;
use yii\web\Response;

/**
 * Smoke test for the control panel pages: renders each page through its
 * controller action (so the PHP and the Twig both run) and checks the page
 * says what it should for each kind of test. Catches Twig errors and missing
 * variables before a release.
 */
class ControlPanelPagesIntegrationTest extends Unit
{
    /** @var int[] */
    private array $testIds = [];

    private ?User $previousIdentity = null;

    private mixed $previousController = null;

    private ?string $previousCookieKey = null;

    protected function _before(): void
    {
        $user = Craft::$app->getUser();
        $this->previousIdentity = $user->getIdentity();

        $admin = User::find()->admin()->status(null)->one();
        if (!$admin) {
            $this->markTestSkipped('Needs an admin user');
        }
        $user->setIdentity($admin);

        // Forms in the pages output CSRF tokens, which need a cookie key
        $request = Craft::$app->getRequest();
        $this->previousCookieKey = $request->cookieValidationKey;
        $request->cookieValidationKey = 'abtestcraft-test-cookie-key';
        $this->previousController = Craft::$app->controller;
    }

    protected function _after(): void
    {
        foreach ($this->testIds as $id) {
            DailyStatsRecord::deleteAll(['testId' => $id]);
            VisitorRecord::deleteAll(['testId' => $id]);
            GoalRecord::deleteAll(['testId' => $id]);
            TestRecord::deleteAll(['id' => $id]);
        }
        Craft::$app->getCache()->flush();
        Craft::$app->getUser()->setIdentity($this->previousIdentity);
        Craft::$app->getRequest()->cookieValidationKey = $this->previousCookieKey;
        Craft::$app->controller = $this->previousController;
    }

    // ========================================
    // Results page, one test per state
    // ========================================

    public function testResultsForADraftWithNoVisitors(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_DRAFT, 'startedAt' => null]);

        $html = $this->results($test);
        $this->assertSame('No visitors yet', $this->headline($html));
        $this->assertStringContainsString('Start the test, or schedule it', $html);
        $this->assertStringNotContainsString('abtc-end-menu', $html, 'No End test menu on a draft');
    }

    public function testResultsForAScheduledDraft(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_DRAFT, 'startedAt' => null, 'scheduledStartAt' => '+3 days']);

        $html = $this->results($test);
        $this->assertSame('No visitors yet', $this->headline($html));
        $this->assertStringContainsString('The test starts', $html);
    }

    public function testResultsTooEarlyToCall(): void
    {
        $test = $this->createTest(['startedAt' => '-2 days']);
        $this->addStats($test, 120, 12, 118, 13);

        $html = $this->results($test);
        $this->assertSame('Too early to call', $this->headline($html));
        $this->assertStringContainsString('abtc-end-menu', $html, 'End test menu');
        $this->assertStringContainsString('End with no winner', $html);
    }

    public function testResultsWhenTheVariantIsWinning(): void
    {
        $test = $this->createTest(['startedAt' => '-20 days']);
        $this->addStats($test, 16000, 1600, 16000, 1920);

        $html = $this->results($test);
        $this->assertStringStartsWith('The variant converts 20', $this->headline($html));
        $this->assertStringContainsString('Show the variant to everyone', $html);
    }

    public function testResultsWhenTheOriginalIsWinning(): void
    {
        $test = $this->createTest(['startedAt' => '-20 days']);
        $this->addStats($test, 16000, 1920, 16000, 1600);

        $html = $this->results($test);
        $this->assertStringStartsWith('The original converts better', $this->headline($html));
        $this->assertStringContainsString('End test, original wins', $html);
    }

    public function testResultsWithNoSignificantDifference(): void
    {
        $test = $this->createTest(['startedAt' => '-30 days']);
        $this->addStats($test, 20000, 2000, 20000, 2010);

        $html = $this->results($test);
        $this->assertSame('No significant difference', $this->headline($html));
    }

    public function testResultsWithAPrimaryGoalAndSeveralGoals(): void
    {
        $test = $this->createTest(['startedAt' => '-20 days', 'primaryGoalType' => Goal::TYPE_FORM, 'goals' => [Goal::TYPE_FORM, Goal::TYPE_PHONE]]);
        $this->addStats($test, 16000, 1600, 16000, 1920, Goal::TYPE_FORM);
        $this->addGoalStats($test, Goal::TYPE_PHONE, 40, 45);

        $html = $this->results($test);
        $this->assertStringStartsWith('The variant converts 20', $this->headline($html));
        $this->assertStringContainsString('Results by goal', $html);
    }

    public function testResultsForAPausedTest(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_PAUSED, 'startedAt' => '-5 days']);
        $this->addStats($test, 300, 30, 290, 31);

        $html = $this->results($test);
        $this->assertSame('Too early to call', $this->headline($html));
        $this->assertStringContainsString('Paused', $html);
    }

    public function testResultsPausedByTheGuardrail(): void
    {
        $test = $this->createTest([
            'status' => Test::STATUS_PAUSED,
            'startedAt' => '-10 days',
            'guardrail' => ['enabled' => true],
            'guardrailTriggeredAt' => '-1 day',
            'guardrailMessage' => 'The variant converted 40% worse than the control.',
        ]);
        $this->addStats($test, 1300, 110, 1300, 66);

        $html = $this->results($test);
        $this->assertSame('Paused by the safety guardrail', $this->headline($html));
        $this->assertStringContainsString('The safety guardrail paused this test', $html);
        $this->assertStringContainsString('The variant converted 40% worse than the control.', $html);
    }

    public function testResultsWhileTheGuardrailIsWaitingForData(): void
    {
        $test = $this->createTest(['startedAt' => '-2 days', 'guardrail' => ['enabled' => true]]);
        $this->addStats($test, 80, 8, 80, 7);

        $html = $this->results($test);
        $this->assertStringContainsString('Safety guardrail is waiting for data.', $html);
    }

    public function testResultsWhileTheGuardrailIsWatching(): void
    {
        $test = $this->createTest(['startedAt' => '-3 days', 'guardrail' => ['enabled' => true, 'minVisitors' => 100, 'minConversions' => 10]]);
        $this->addStats($test, 400, 40, 400, 42);

        $html = $this->results($test);
        $this->assertStringContainsString('Safety guardrail is watching.', $html);
    }

    public function testResultsWithASampleRatioMismatch(): void
    {
        $test = $this->createTest(['startedAt' => '-10 days']);
        $this->addStats($test, 3000, 300, 2000, 200);

        $html = $this->results($test);
        $this->assertStringContainsString('% of visitors are seeing the variant, but the test sends', $html);
    }

    public function testResultsForACompletedTestServingTheVariant(): void
    {
        $test = $this->createTest([
            'status' => Test::STATUS_COMPLETED,
            'startedAt' => '-30 days',
            'endedAt' => '-2 days',
            'winnerVariant' => Test::VARIANT_VARIANT,
            'serveWinner' => true,
            'learnings' => 'Large images won.',
        ]);
        $this->addStats($test, 16000, 1600, 16000, 1920);

        $html = $this->results($test);
        $this->assertStringStartsWith('The variant won', $this->headline($html));
        $this->assertStringContainsString('The variant is shown to everyone', $html);
        $this->assertStringContainsString('Large images won.', $html);
        $this->assertStringNotContainsString('abtc-end-menu', $html, 'No End test menu once ended');
    }

    public function testResultsForACompletedTestTheOriginalWon(): void
    {
        $test = $this->createTest([
            'status' => Test::STATUS_COMPLETED,
            'startedAt' => '-30 days',
            'endedAt' => '-2 days',
            'winnerVariant' => Test::VARIANT_CONTROL,
        ]);
        $this->addStats($test, 16000, 1920, 16000, 1600);

        $html = $this->results($test);
        $this->assertStringStartsWith('The original won', $this->headline($html));
    }

    public function testResultsForACompletedTestWithNoWinner(): void
    {
        $test = $this->createTest(['status' => Test::STATUS_COMPLETED, 'startedAt' => '-30 days', 'endedAt' => '-2 days']);
        $this->addStats($test, 5000, 500, 5000, 505);

        $html = $this->results($test);
        $this->assertSame('Ended with no winner', $this->headline($html));
    }

    public function testResultsWhenTheVariantEntryIsGone(): void
    {
        $test = $this->createTest(['startedAt' => '-5 days', 'variantEntryId' => $this->missingElementId()]);
        $this->addStats($test, 100, 10, 100, 11);

        $html = $this->results($test);
        $this->assertStringContainsString("This test's variant entry is missing", $html);
    }

    // ========================================
    // Tests list, edit form, settings
    // ========================================

    public function testTestsListTabs(): void
    {
        $this->createTest(['name' => 'Smoke running test', 'startedAt' => '-20 days']);
        $this->addStats($this->lastTest(), 5000, 500, 5000, 700);
        $this->createTest(['name' => 'Smoke completed test', 'status' => Test::STATUS_COMPLETED, 'startedAt' => '-30 days', 'endedAt' => '-1 day']);
        $this->createTest(['name' => 'Smoke draft test', 'status' => Test::STATUS_DRAFT, 'startedAt' => null]);

        $this->assertStringContainsString('Smoke running test', $this->page('tests/index'));
        $this->assertStringContainsString('Smoke draft test', $this->page('tests/index', ['status' => 'active']));
        $this->assertStringContainsString('Smoke completed test', $this->page('tests/index', ['status' => 'completed']));
        $this->assertStringContainsString('Smoke completed test', $this->page('tests/index', ['status' => 'all']));
        $this->page('tests/index', ['status' => 'trashed']);
    }

    public function testNewTestForm(): void
    {
        $html = $this->page('tests/new');
        $this->assertStringContainsString('name="name"', $html);
    }

    public function testEditFormForEachStatus(): void
    {
        foreach ([Test::STATUS_DRAFT, Test::STATUS_RUNNING, Test::STATUS_PAUSED, Test::STATUS_COMPLETED] as $status) {
            $test = $this->createTest(['name' => "Smoke {$status} form", 'status' => $status]);
            $html = $this->page('tests/edit', [], ['testId' => $test->id]);
            $this->assertStringContainsString("Smoke {$status} form", $html, $status);
        }
    }

    public function testSettingsPage(): void
    {
        $html = $this->page('plugins/edit-plugin-settings', [], ['handle' => 'abtestcraft']);
        $this->assertStringContainsString('ABTestCraft', $html);
    }

    // ========================================
    // Helpers
    // ========================================

    private function results(Test $test): string
    {
        $html = $this->page('tests/results', [], ['testId' => $test->id]);
        $this->assertStringContainsString(htmlspecialchars($test->name), $html);

        return $html;
    }

    /**
     * The results page's verdict headline, as plain text
     */
    private function headline(string $html): string
    {
        $this->assertMatchesRegularExpression('/<h2 id="abtc-verdict-title">(.*?)<\/h2>/s', $html, 'Verdict headline');
        preg_match('/<h2 id="abtc-verdict-title">(.*?)<\/h2>/s', $html, $match);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[1]))));
    }

    /**
     * Runs a control panel action and returns the page HTML
     *
     * @param array<string, mixed> $query Query string parameters
     * @param array<string, mixed> $params Route parameters
     */
    private function page(string $route, array $query = [], array $params = []): string
    {
        Craft::$app->getRequest()->setQueryParams($query);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        // The test app is a console request, so controller routes resolve to
        // console controllers: run the web controller directly
        [$controllerId, $actionId] = explode('/', $route, 2);
        $controller = $controllerId === 'plugins'
            ? new PluginsController($controllerId, Craft::$app)
            : new TestsController($controllerId, ABTestCraft::getInstance());
        // Some pages render through Craft::$app->controller (plugin settings)
        Craft::$app->controller = $controller;
        $response = $controller->runAction($actionId, $params);
        $this->assertInstanceOf(Response::class, $response, $route);
        $this->assertSame(200, $response->getStatusCode(), $route);

        // Render the template the way sending the response would
        (fn() => $this->prepare())->call($response);
        $html = (string) $response->content;
        $this->assertStringContainsString('</html>', $html, "{$route} rendered a whole page");

        return $html;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createTest(array $attributes = []): Test
    {
        [$controlId, $variantId] = array_map('intval', (new Query())
            ->select(['id'])->from('{{%entries}}')->orderBy(['id' => SORT_ASC])->limit(2)->column());

        $record = new TestRecord();
        $record->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $record->name = $attributes['name'] ?? 'Smoke test ' . StringHelper::randomString(6);
        $record->handle = 'smoke' . StringHelper::randomString(8);
        $record->hypothesis = 'A bigger button gets more clicks.';
        $record->variantDescription = 'Bigger button.';
        $record->learnings = $attributes['learnings'] ?? null;
        $record->status = $attributes['status'] ?? Test::STATUS_RUNNING;
        $record->controlEntryId = $attributes['controlEntryId'] ?? $controlId;
        $record->variantEntryId = $attributes['variantEntryId'] ?? $variantId;
        $record->trafficSplit = 50;
        $goals = $attributes['goals'] ?? [Goal::TYPE_FORM];
        $record->goalType = $goals[0];
        $record->primaryGoalType = $attributes['primaryGoalType'] ?? null;
        $record->whenWinnerFound = Test::WINNER_NOTIFY;
        $record->winnerVariant = $attributes['winnerVariant'] ?? null;
        $record->serveWinner = $attributes['serveWinner'] ?? false;
        $record->guardrail = isset($attributes['guardrail']) ? array_merge(Test::GUARDRAIL_DEFAULTS, $attributes['guardrail']) : null;
        $record->guardrailMessage = $attributes['guardrailMessage'] ?? null;
        $dates = ['startedAt' => '-10 days', 'endedAt' => null, 'scheduledStartAt' => null, 'scheduledEndAt' => null, 'guardrailTriggeredAt' => null];
        foreach ($dates as $field => $default) {
            $value = array_key_exists($field, $attributes) ? $attributes[$field] : $default;
            $record->$field = $value !== null ? Db::prepareDateForDb(new DateTime($value)) : null;
        }
        $record->save(false);
        $this->testIds[] = (int) $record->id;

        foreach ($goals as $i => $goalType) {
            $goal = new GoalRecord();
            $goal->testId = $record->id;
            $goal->goalType = $goalType;
            $goal->isEnabled = true;
            $goal->config = $goalType === Goal::TYPE_FORM ? ['mode' => 'smart'] : [];
            $goal->sortOrder = $i;
            $goal->save(false);
        }

        return $this->lastTest();
    }

    private function lastTest(): Test
    {
        $test = ABTestCraft::getInstance()->tests->getTestById(end($this->testIds));
        $this->assertNotNull($test);

        return $test;
    }

    /**
     * Visitors and converted visitors for each version, spread over the test's
     * days, plus the same conversions under the goal type
     */
    private function addStats(Test $test, int $controlVisitors, int $controlConverted, int $variantVisitors, int $variantConverted, string $goalType = Goal::TYPE_FORM): void
    {
        $days = max(1, (int) ($test->startedAt?->diff(new DateTime())->days ?? 1));
        $totals = [
            Test::VARIANT_CONTROL => [$controlVisitors, $controlConverted],
            Test::VARIANT_VARIANT => [$variantVisitors, $variantConverted],
        ];
        foreach ($totals as $variant => [$visitors, $converted]) {
            for ($day = 0; $day < $days; $day++) {
                $share = fn(int $total) => intdiv($total, $days) + ($day < $total % $days ? 1 : 0);
                $date = (clone $test->startedAt)->modify("+{$day} days")->format('Y-m-d');
                $this->insertStats($test, $date, $variant, null, $share($visitors), $share($converted));
                $this->insertStats($test, $date, $variant, $goalType, 0, $share($converted));
            }
        }
    }

    private function addGoalStats(Test $test, string $goalType, int $controlConverted, int $variantConverted): void
    {
        $date = (new DateTime())->format('Y-m-d');
        $this->insertStats($test, $date, Test::VARIANT_CONTROL, $goalType, 0, $controlConverted);
        $this->insertStats($test, $date, Test::VARIANT_VARIANT, $goalType, 0, $variantConverted);
    }

    private function insertStats(Test $test, string $date, string $variant, ?string $goalType, int $visitors, int $converted): void
    {
        $record = new DailyStatsRecord();
        $record->testId = $test->id;
        $record->date = $date;
        $record->variant = $variant;
        $record->goalType = $goalType;
        $record->impressions = $goalType === null ? (int) round($visitors * 1.3) : 0;
        $record->conversions = $converted;
        $record->visitors = $visitors;
        $record->convertedVisitors = $converted;
        $record->save(false);
    }

    /**
     * An element ID that exists (for the foreign key) but isn't an entry
     */
    private function missingElementId(): int
    {
        $id = (new Query())->select(['id'])->from('{{%elements}}')
            ->where(['not in', 'id', (new Query())->select(['id'])->from('{{%entries}}')])
            ->scalar();
        if (!$id) {
            $this->markTestSkipped('Needs an element that is not an entry');
        }

        return (int) $id;
    }
}
