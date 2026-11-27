<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\TestRecord;

/**
 * Rules that act on tests by themselves:
 *
 * - scheduled starts and ends
 * - the safety guardrail (pause or end a test whose variant is clearly
 *   doing much worse)
 * - "When a winner is found" (end the test, optionally serving the winner)
 *   and the "has a winner" email
 * - the weekly summary
 *
 * They're checked after each conversion (for that test) and by a check that
 * runs at most once a minute during normal requests, or from cron with
 * `./craft abtestcraft/tests/run-scheduled`.
 */
class TestRulesService extends Component
{
    private const TICK_CACHE_KEY = 'abtestcraft_rules_tick';
    private const TICK_INTERVAL = 60;

    /**
     * Run the checks if they haven't run in the last minute. Called during
     * normal requests; never throws.
     */
    public function maybeTick(): void
    {
        try {
            if (!Craft::$app->getCache()->add(self::TICK_CACHE_KEY, time(), self::TICK_INTERVAL)) {
                return;
            }
            $this->tick();
        } catch (\Throwable $e) {
            Craft::error('ABTestCraft scheduled check failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Run every check now
     *
     * @return string[] What happened, for the console command
     */
    public function tick(): array
    {
        $log = $this->runSchedules();

        foreach (ABTestCraft::getInstance()->tests->getTestsByStatus('active') as $test) {
            if ($test->isRunning() && ($result = $this->checkTest($test)) !== null) {
                $log[] = $result;
            }
        }

        if (ABTestCraft::getInstance()->notifications->sendWeeklySummaryIfDue()) {
            $log[] = 'Sent the weekly summary.';
        }

        return $log;
    }

    /**
     * Apply the guardrail and the "When a winner is found" rule to a running
     * test, and send its winner email
     *
     * @return string|null What happened, if anything
     */
    public function checkTest(Test $test): ?string
    {
        if (!$test->id) {
            return null;
        }

        // One check per test at a time (conversions can arrive together)
        $mutex = Craft::$app->getMutex();
        $lock = "abtestcraft-rules-{$test->id}";
        if (!$mutex->acquire($lock)) {
            return null;
        }

        try {
            $test = ABTestCraft::getInstance()->tests->getTestById($test->id);
            if (!$test || !$test->isRunning()) {
                return null;
            }

            $stats = ABTestCraft::getInstance()->stats->getSummaryStats($test);

            // Safety first
            if ($stats['guardrail']['shouldTrigger']) {
                return $this->triggerGuardrail($test, $stats);
            }

            if (!$stats['isSignificant'] || $stats['winner'] === null) {
                return null;
            }

            if ($test->whenWinnerFound === Test::WINNER_NOTIFY) {
                ABTestCraft::getInstance()->notifications->checkAndNotifySignificance($test, $stats);
                return null;
            }

            $serve = $test->whenWinnerFound === Test::WINNER_SERVE;
            if (!ABTestCraft::getInstance()->tests->completeTest($test, $stats['winner'], $serve)) {
                return null;
            }

            ABTestCraft::getInstance()->notifications->sendWinnerApplied($test, $stats);

            return "'{$test->name}' ended automatically: " . ucfirst($stats['winner']) . ' won'
                . ($test->isServingWinner() ? ', variant shown to everyone.' : '.');
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Start and end tests whose scheduled time has come
     *
     * @return string[]
     */
    public function runSchedules(): array
    {
        $log = [];
        $tests = ABTestCraft::getInstance()->tests;
        $notifications = ABTestCraft::getInstance()->notifications;
        $now = Db::prepareDateForDb(new DateTime());

        $dueStarts = TestRecord::find()
            ->where(['status' => [Test::STATUS_DRAFT, Test::STATUS_PAUSED], 'dateDeleted' => null])
            ->andWhere(['not', ['scheduledStartAt' => null]])
            ->andWhere(['<=', 'scheduledStartAt', $now])
            ->all();

        foreach ($dueStarts as $record) {
            $test = $tests->getTestById((int) $record->id);
            if (!$test) {
                continue;
            }

            $result = $tests->startTest($test);
            if ($result === true) {
                $notifications->sendScheduledStart($test);
                $log[] = "'{$test->name}' started on schedule.";
                continue;
            }

            // Don't retry every minute: clear the schedule and say why
            $test->scheduledStartAt = null;
            TestRecord::updateAll(['scheduledStartAt' => null], ['id' => $test->id]);
            $notifications->sendScheduledStartFailed($test, (string) $result);
            Craft::warning("Scheduled start of test '{$test->handle}' failed: {$result}", __METHOD__);
            $log[] = "'{$test->name}' couldn't start: {$result}";
        }

        $dueEnds = TestRecord::find()
            ->where(['status' => [Test::STATUS_RUNNING, Test::STATUS_PAUSED], 'dateDeleted' => null])
            ->andWhere(['not', ['scheduledEndAt' => null]])
            ->andWhere(['<=', 'scheduledEndAt', $now])
            ->all();

        foreach ($dueEnds as $record) {
            $test = $tests->getTestById((int) $record->id);
            if (!$test) {
                continue;
            }

            // A winner only if the result can be called; serve it if the test is set to
            $stats = ABTestCraft::getInstance()->stats->getSummaryStats($test);
            $winner = $stats['isSignificant'] ? $stats['winner'] : null;
            $serve = $test->whenWinnerFound === Test::WINNER_SERVE;

            if ($tests->completeTest($test, $winner, $serve)) {
                $notifications->sendScheduledEnd($test, $stats);
                $log[] = "'{$test->name}' ended on schedule" . ($winner ? ': ' . ucfirst($winner) . ' won.' : '.');
            }
        }

        return $log;
    }

    /**
     * Pause or end a test whose variant is clearly doing much worse
     */
    private function triggerGuardrail(Test $test, array $stats): string
    {
        $guardrail = $stats['guardrail'];
        $confidence = rtrim(rtrim(number_format($guardrail['confidence'] * 100, 1), '0'), '.');

        $test->guardrailTriggeredAt = new DateTime();
        $test->guardrailMessage = sprintf(
            'The variant converted %s%% worse than the control on %s (%s%% of %d visitors, against %s%% of %d), '
            . 'past the %d%% limit. A drop this large is very unlikely to be chance (%s%% confidence, on a test '
            . 'that stays reliable when checked continuously).',
            $guardrail['observedDrop'],
            $guardrail['goalLabel'],
            $guardrail['variantRate'],
            $guardrail['visitors'][Test::VARIANT_VARIANT],
            $guardrail['controlRate'],
            $guardrail['visitors'][Test::VARIANT_CONTROL],
            $guardrail['drop'],
            $confidence
        );

        $tests = ABTestCraft::getInstance()->tests;
        $done = $guardrail['action'] === Test::GUARDRAIL_END
            ? $tests->completeTest($test, Test::VARIANT_CONTROL)
            : $tests->pauseTest($test);

        if (!$done) {
            Craft::error("The safety guardrail couldn't stop test '{$test->handle}'", __METHOD__);
            return "'{$test->name}': the guardrail triggered but couldn't stop the test.";
        }

        Craft::warning("Safety guardrail stopped test '{$test->handle}': {$test->guardrailMessage}", __METHOD__);
        ABTestCraft::getInstance()->notifications->sendGuardrailTriggered($test);

        return "'{$test->name}' was " . ($test->status === Test::STATUS_PAUSED ? 'paused' : 'ended')
            . ' by its safety guardrail.';
    }
}
