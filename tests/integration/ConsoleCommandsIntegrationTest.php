<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;

/**
 * The `./craft abtestcraft/tests/...` commands, run as real console commands
 * on the site this suite's database belongs to.
 *
 * Some of them act on every test in the database (cleanup, run-scheduled), so
 * this only runs on a disposable test site: set ABTESTCRAFT_TEST_SITE_URL (in
 * tests/.env, as tests/ci/setup-site.sh does).
 */
class ConsoleCommandsIntegrationTest extends Unit
{
    /** @var int[] */
    private array $testIds = [];

    protected function _before(): void
    {
        if (!App::env('ABTESTCRAFT_TEST_SITE_URL')) {
            $this->markTestSkipped('Runs only on a disposable test site (ABTESTCRAFT_TEST_SITE_URL, see tests/ci/setup-site.sh)');
        }
        if (!is_file(CRAFT_ROOT_PATH . '/craft')) {
            $this->markTestSkipped('No craft executable in ' . CRAFT_ROOT_PATH);
        }
    }

    protected function _after(): void
    {
        foreach ($this->testIds as $id) {
            Craft::$app->getDb()->createCommand()->delete('{{%abtestcraft_visitor_goals}}', ['testId' => $id])->execute();
            DailyStatsRecord::deleteAll(['testId' => $id]);
            VisitorRecord::deleteAll(['testId' => $id]);
            GoalRecord::deleteAll(['testId' => $id]);
            TestRecord::deleteAll(['id' => $id]);
        }
    }

    public function testList(): void
    {
        $test = $this->createTest(['name' => 'Console list test']);

        [$code, $output] = $this->craft('abtestcraft/tests/list');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString($test->handle, $output);
        $this->assertStringContainsString('Console list test', $output);
    }

    public function testStats(): void
    {
        $test = $this->createTest();
        $this->addStats($test, 'control', 200, 20);
        $this->addStats($test, 'variant', 180, 27);

        [$code, $output] = $this->craft('abtestcraft/tests/stats', $test->handle);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Visitors: 200', $output);
        $this->assertStringContainsString('Visitors: 180', $output);
        $this->assertStringContainsString('Conversion rate: 15', $output);

        [$code] = $this->craft('abtestcraft/tests/stats', 'noSuchTest');
        $this->assertNotSame(0, $code, 'Unknown test');
    }

    public function testExport(): void
    {
        $test = $this->createTest();
        $this->addStats($test, 'control', 200, 20);
        $this->addStats($test, 'variant', 180, 27);

        [$code, $output] = $this->craft('abtestcraft/tests/export', (string) $test->id);
        $this->assertSame(0, $code, $output);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($output))));
        $this->assertSame(['Metric', 'Control', 'Variant'], $rows[0]);
        $this->assertSame(['Visitors', '200', '180'], $rows[1]);
        $this->assertSame(['Converted visitors', '20', '27'], $rows[2]);
    }

    public function testRecalculateStats(): void
    {
        $test = $this->createTest();
        $this->addStats($test, 'control', 50, 5);

        [$code, $output] = $this->craft('abtestcraft/tests/recalculate-stats', $test->handle, '--force');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Visitors: 50 (control) / 0 (variant)', $output);
    }

    public function testCleanupRemovesOnlyOldVisitorsOfCompletedTests(): void
    {
        $completed = $this->createTest(['status' => Test::STATUS_COMPLETED, 'endedAt' => '-100 days']);
        $running = $this->createTest();
        foreach ([$completed, $running] as $test) {
            $this->addVisitor($test, '-200 days');
            $this->addVisitor($test, '-10 days');
        }

        [$code, $output] = $this->craft('abtestcraft/tests/cleanup', '--days=90', '--force');
        $this->assertSame(0, $code, $output);

        $this->assertSame(1, $this->visitorCount($completed), 'Old visitor of the completed test removed');
        $this->assertSame(2, $this->visitorCount($running), 'Running tests keep every visitor');
    }

    public function testRunScheduledStartsAndEndsTests(): void
    {
        $toStart = $this->createTest(['status' => Test::STATUS_DRAFT, 'startedAt' => null, 'scheduledStartAt' => '-5 minutes', 'page' => 3]);
        $toEnd = $this->createTest(['scheduledEndAt' => '-5 minutes', 'page' => 2]);
        $later = $this->createTest(['status' => Test::STATUS_DRAFT, 'startedAt' => null, 'scheduledStartAt' => '+1 day', 'page' => 2]);

        [$code, $output] = $this->craft('abtestcraft/tests/run-scheduled');
        $this->assertSame(0, $code, $output);

        $this->assertSame(Test::STATUS_RUNNING, $this->testStatus($toStart), $output);
        $this->assertSame(Test::STATUS_COMPLETED, $this->testStatus($toEnd), $output);
        $this->assertSame(Test::STATUS_DRAFT, $this->testStatus($later), 'Not due yet');
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Runs a craft console command on the site, with this suite's database
     *
     * @return array{0: int, 1: string} Exit code and standard output
     */
    private function craft(string ...$args): array
    {
        $db = Craft::$app->getDb();
        $env = array_merge(getenv(), [
            'CRAFT_DB_DSN' => $db->dsn,
            'CRAFT_DB_USER' => (string) $db->username,
            'CRAFT_DB_PASSWORD' => (string) $db->password,
            'CRAFT_DB_TABLE_PREFIX' => (string) $db->tablePrefix,
        ]);
        $command = array_merge([PHP_BINARY, CRAFT_ROOT_PATH . '/craft'], $args, ['--interactive=0', '--color=0']);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CRAFT_ROOT_PATH, $env);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, $output . ($code !== 0 ? "\n" . $errors : '')];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createTest(array $attributes = []): Test
    {
        // Away from the first entries, which the front-end tests use; each
        // running test needs a page of its own
        $ids = array_map('intval', (new Query())->select(['id'])->from('{{%entries}}')->orderBy(['id' => SORT_ASC])->column());
        $controlId = $ids[$attributes['page'] ?? 3] ?? null;
        $variantId = $ids[4] ?? null;
        if (!$controlId || !$variantId) {
            $this->markTestSkipped('Needs the Pages section from tests/ci/seed.php');
        }

        $record = new TestRecord();
        $record->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $record->name = $attributes['name'] ?? 'Console test';
        $record->handle = 'console' . StringHelper::randomString(8);
        $record->status = $attributes['status'] ?? Test::STATUS_RUNNING;
        $record->controlEntryId = $controlId;
        $record->variantEntryId = $variantId;
        $record->trafficSplit = 50;
        $record->goalType = Goal::TYPE_FORM;
        $record->whenWinnerFound = Test::WINNER_NOTIFY;
        $dates = ['startedAt' => '-10 days', 'endedAt' => null, 'scheduledStartAt' => null, 'scheduledEndAt' => null];
        foreach ($dates as $field => $default) {
            $value = array_key_exists($field, $attributes) ? $attributes[$field] : $default;
            $record->$field = $value !== null ? Db::prepareDateForDb(new DateTime($value)) : null;
        }
        $record->save(false);
        $this->testIds[] = (int) $record->id;

        $goal = new GoalRecord();
        $goal->testId = $record->id;
        $goal->goalType = Goal::TYPE_FORM;
        $goal->isEnabled = true;
        $goal->config = ['mode' => 'smart'];
        $goal->sortOrder = 0;
        $goal->save(false);

        $test = \livehand\abtestcraft\ABTestCraft::getInstance()->tests->getTestById((int) $record->id);
        $this->assertNotNull($test);

        return $test;
    }

    private function addStats(Test $test, string $variant, int $visitors, int $converted): void
    {
        foreach ([null, Goal::TYPE_FORM] as $goalType) {
            $record = new DailyStatsRecord();
            $record->testId = $test->id;
            $record->date = (new DateTime('-1 day'))->format('Y-m-d');
            $record->variant = $variant;
            $record->goalType = $goalType;
            $record->impressions = $goalType === null ? $visitors : 0;
            $record->conversions = $converted;
            $record->visitors = $goalType === null ? $visitors : 0;
            $record->convertedVisitors = $converted;
            $record->save(false);
        }
    }

    private function addVisitor(Test $test, string $when): void
    {
        $date = Db::prepareDateForDb(new DateTime($when));
        Craft::$app->getDb()->createCommand()->insert('{{%abtestcraft_visitors}}', [
            'testId' => $test->id,
            'visitorId' => StringHelper::UUID(),
            'variant' => Test::VARIANT_CONTROL,
            'converted' => false,
            'dateCreated' => $date,
            'dateUpdated' => $date,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function visitorCount(Test $test): int
    {
        return (int) VisitorRecord::find()->where(['testId' => $test->id])->count();
    }

    private function testStatus(Test $test): string
    {
        return (string) (new Query())->select(['status'])->from('{{%abtestcraft_tests}}')->where(['id' => $test->id])->scalar();
    }
}
