<?php

declare(strict_types=1);

namespace livehand\abtestcraft\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Db;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\ABTestCraft;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console commands for ABTestCraft tests: ./craft abtestcraft/tests/<command>
 *
 * Before 1.0.2 these were only reachable as abtestcraft/a-b-test-craft/<command>;
 * that route still works (see ABTestCraft::init()).
 */
class TestsController extends Controller
{
    /**
     * @var bool Whether to force the operation without confirmation
     */
    public bool $force = false;

    /**
     * @var int Number of days to keep visitor data (for cleanup command)
     */
    public int $days = 90;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'cleanup':
                $options[] = 'days';
                $options[] = 'force';
                break;
            case 'recalculate-stats':
                $options[] = 'force';
                break;
        }

        return $options;
    }

    /**
     * List all A/B tests with their status
     *
     * Usage: ./craft abtestcraft/tests/list
     */
    public function actionList(): int
    {
        $tests = ABTestCraft::getInstance()->tests->getAllTests();

        if (empty($tests)) {
            $this->stdout("No A/B tests found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n");
        $this->stdout(str_pad('ID', 6) . str_pad('Status', 12) . str_pad('Handle', 30) . "Name\n", Console::BOLD);
        $this->stdout(str_repeat('-', 80) . "\n");

        foreach ($tests as $test) {
            $statusColor = match ($test->status) {
                Test::STATUS_RUNNING => Console::FG_GREEN,
                Test::STATUS_PAUSED => Console::FG_YELLOW,
                Test::STATUS_COMPLETED => Console::FG_CYAN,
                default => Console::FG_GREY,
            };

            $this->stdout(str_pad((string)$test->id, 6));
            $this->stdout(str_pad($test->status, 12), $statusColor);
            $this->stdout(str_pad($test->handle, 30));
            $this->stdout($test->name . "\n");
        }

        $this->stdout("\n");
        $this->stdout("Total: " . count($tests) . " tests\n", Console::BOLD);

        return ExitCode::OK;
    }

    /**
     * Show detailed stats for a specific test
     *
     * Usage: ./craft abtestcraft/tests/stats <testId or handle>
     *
     * @param string $test The test's ID or handle
     */
    public function actionStats(string $test): int
    {
        $model = $this->findTest($test);
        if (!$model) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $stats = ABTestCraft::getInstance()->stats->getTestStats($model);

        $this->stdout("\n");
        $this->stdout("ABTestCraft: {$model->name}\n", Console::BOLD);
        $this->stdout("Handle: {$model->handle}\n");
        $this->stdout("Status: {$model->status}\n");
        $this->stdout("Primary goal: {$stats['primaryGoalLabel']}\n");
        $this->stdout(str_repeat('-', 50) . "\n\n");

        foreach ([Test::VARIANT_CONTROL => 'Control', Test::VARIANT_VARIANT => 'Variant'] as $variant => $label) {
            $this->stdout("{$label}:\n", Console::BOLD);
            $this->stdout("  Visitors: {$stats['visitors'][$variant]}\n");
            $this->stdout("  Converted: {$stats['convertedVisitors'][$variant]}\n");
            $this->stdout("  Conversion rate: {$stats['conversionRates'][$variant]}%\n");
            $this->stdout("  Page views: {$stats['pageViews'][$variant]}\n");
            $this->stdout("  Conversions (all events): {$stats['conversions'][$variant]}\n\n");
        }

        $this->stdout("Statistical Significance:\n", Console::BOLD);
        $confidence = number_format($stats['confidence'] * 100, 1);
        $significanceColor = $stats['isSignificant'] ? Console::FG_GREEN : Console::FG_YELLOW;
        $provisional = $stats['verdictReady'] ? '' : ' (provisional)';
        $this->stdout("  Confidence: {$confidence}%{$provisional}\n", $significanceColor);
        $this->stdout("  Significant: " . ($stats['isSignificant'] ? 'Yes' : 'No') . "\n", $significanceColor);
        $this->stdout("  Planned sample: {$stats['sampleSizeNeeded']} visitors per version"
            . ($stats['sampleReached'] ? ' (reached)' : '') . "\n");
        $this->stdout("  Days running: {$stats['daysRunning']} of at least {$stats['minimumDays']}\n");

        if ($stats['winner']) {
            $this->stdout("  Winner: " . ucfirst($stats['winner']) . "\n", Console::FG_GREEN);
        }

        if ($stats['improvement'] !== null) {
            $improvementStr = ($stats['improvement'] >= 0 ? '+' : '') . $stats['improvement'] . '%';
            $improvementColor = $stats['improvement'] >= 0 ? Console::FG_GREEN : Console::FG_RED;
            $this->stdout("  Improvement: {$improvementStr}\n", $improvementColor);
        }

        if ($stats['srm']['detected'] ?? false) {
            $this->stdout(
                "  Warning: visitors split {$stats['srm']['actualVariantPercent']}% to the variant, "
                . "expected {$stats['srm']['expectedVariantPercent']}%. Results may be unreliable.\n",
                Console::FG_RED
            );
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Recalculate and clear cached stats for a test
     *
     * Usage: ./craft abtestcraft/tests/recalculate-stats <testId or handle>
     *
     * @param string $test The test's ID or handle
     */
    public function actionRecalculateStats(string $test): int
    {
        $testModel = $this->findTest($test);
        if (!$testModel) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $test = $testModel;
        $testId = (int) $test->id;

        if (!$this->force) {
            $confirm = $this->confirm("Recalculate stats for test '{$test->name}'?");
            if (!$confirm) {
                $this->stdout("Aborted.\n");
                return ExitCode::OK;
            }
        }

        // Clear the stats cache
        ABTestCraft::getInstance()->stats->invalidateStatsCache($testId);

        // Force recalculation by fetching stats
        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        $this->stdout("Stats cache cleared and recalculated for test: {$test->name}\n", Console::FG_GREEN);
        $visitors = $stats['visitors'];
        $rates = $stats['conversionRates'];
        $this->stdout("  Visitors: {$visitors['control']} (control) / {$visitors['variant']} (variant)\n");
        $this->stdout("  Conversion rate: {$rates['control']}% (control) / {$rates['variant']}% (variant)\n");

        return ExitCode::OK;
    }

    /**
     * Clean up old visitor data for completed tests
     *
     * Usage: ./craft abtestcraft/tests/cleanup --days=90
     *
     * @return int
     */
    public function actionCleanup(): int
    {
        $this->stdout("\n");
        $this->stdout("ABTestCraft Cleanup\n", Console::BOLD);
        $this->stdout(str_repeat('-', 50) . "\n");
        $this->stdout("Cleaning visitor records older than {$this->days} days for completed tests...\n\n");

        // Find completed tests
        $completedTests = array_filter(
            ABTestCraft::getInstance()->tests->getAllTests(),
            fn($test) => $test->isCompleted()
        );

        if (empty($completedTests)) {
            $this->stdout("No completed tests found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Found " . count($completedTests) . " completed test(s).\n");

        if (!$this->force) {
            $confirm = $this->confirm("Proceed with cleanup?");
            if (!$confirm) {
                $this->stdout("Aborted.\n");
                return ExitCode::OK;
            }
        }

        // Stored dates are UTC
        $cutoffDate = Db::prepareDateForDb((new \DateTime())->modify("-{$this->days} days"));
        $totalDeleted = 0;

        foreach ($completedTests as $test) {
            $condition = ['and', ['testId' => $test->id], ['<', 'dateCreated', $cutoffDate]];

            // Their goal slots first (they're keyed by visitor ID), then the visitors.
            // Results come from the daily stats, so they don't change.
            $oldVisitorIds = (new Query())
                ->select(['visitorId'])
                ->from('{{%abtestcraft_visitors}}')
                ->where($condition);
            Craft::$app->getDb()->createCommand()
                ->delete('{{%abtestcraft_visitor_goals}}', [
                    'testId' => $test->id,
                    'visitorId' => $oldVisitorIds,
                ])
                ->execute();

            $deleted = Craft::$app->getDb()->createCommand()
                ->delete('{{%abtestcraft_visitors}}', $condition)
                ->execute();

            if ($deleted > 0) {
                $this->stdout("  {$test->name}: Deleted {$deleted} visitor records\n");
                $totalDeleted += $deleted;
            }
        }

        $this->stdout("\n");
        if ($totalDeleted > 0) {
            $this->stdout("Total deleted: {$totalDeleted} visitor records\n", Console::FG_GREEN);
        } else {
            $this->stdout("No records needed cleanup.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Export test results to CSV
     *
     * Usage: ./craft abtestcraft/tests/export <testId or handle> > results.csv
     * (only the CSV goes to standard output)
     *
     * @param string $test The test's ID or handle
     */
    public function actionExport(string $test): int
    {
        $model = $this->findTest($test);
        if (!$model) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $stats = ABTestCraft::getInstance()->stats->getTestStats($model);

        $rows = [
            ['Metric', 'Control', 'Variant'],
            ['Visitors', $stats['visitors']['control'], $stats['visitors']['variant']],
            ['Converted visitors', $stats['convertedVisitors']['control'], $stats['convertedVisitors']['variant']],
            ['Conversion rate (%)', $stats['conversionRates']['control'], $stats['conversionRates']['variant']],
            ['Page views', $stats['pageViews']['control'], $stats['pageViews']['variant']],
            ['Conversions (all events)', $stats['conversions']['control'], $stats['conversions']['variant']],
        ];
        foreach ($stats['goalStats'] as $goalType => $goal) {
            $rows[] = [
                "Converted visitors: {$goalType}",
                $goal['control']['convertedVisitors'],
                $goal['variant']['convertedVisitors'],
            ];
        }
        $rows[] = [];
        $rows[] = ['Primary goal', $stats['primaryGoalLabel']];
        $rows[] = ['Confidence (%)', number_format($stats['confidence'] * 100, 1)];
        $rows[] = ['Planned sample per version', $stats['sampleSizeNeeded']];
        $rows[] = ['Days running', $stats['daysRunning']];
        $rows[] = ['Significant', $stats['isSignificant'] ? 'Yes' : 'No'];
        $rows[] = ['Winner', $stats['winner'] ?? 'None'];
        $rows[] = ['Improvement (%)', $stats['improvement'] ?? 'N/A'];

        $stream = fopen('php://output', 'w');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        fclose($stream);

        return ExitCode::OK;
    }

    /**
     * Run scheduled starts and ends, safety guardrails, "When a winner is
     * found" and the weekly summary now. They also run during normal site
     * traffic (at most once a minute); run this from cron every few minutes
     * so they happen on time on quiet sites.
     *
     * Usage: ./craft abtestcraft/tests/run-scheduled
     */
    public function actionRunScheduled(): int
    {
        $log = ABTestCraft::getInstance()->rules->tick();

        if ($log === []) {
            $this->stdout("Nothing to do.\n");
        }
        foreach ($log as $line) {
            $this->stdout("{$line}\n");
        }

        return ExitCode::OK;
    }

    /**
     * Find a test by ID or handle, printing an error when there's none
     */
    private function findTest(string $idOrHandle): ?Test
    {
        $tests = ABTestCraft::getInstance()->tests;
        $test = ctype_digit($idOrHandle)
            ? $tests->getTestById((int) $idOrHandle)
            : $tests->getTestByHandle($idOrHandle);

        if (!$test) {
            $this->stderr("Test not found: {$idOrHandle}\n", Console::FG_RED);
        }

        return $test;
    }
}
