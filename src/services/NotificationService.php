<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\TestRecord;

/**
 * Email notifications, sent to the Notification Emails in the settings.
 *
 * - "Has a winner": once per test, when a result can be called (can be
 *   switched off with Send Significance Email)
 * - The safety guardrail pausing or ending a test
 * - A winner applied automatically ("When a winner is found")
 * - Scheduled starts and ends, and scheduled starts that couldn't happen
 * - The weekly summary (off until switched on)
 *
 * Nothing is sent when no notification address is set; the control panel
 * warns about that.
 */
class NotificationService extends Component
{
    private const WEEKLY_SUMMARY_KEY = 'weeklySummarySentOn';

    /** The weekly summary goes out on Monday mornings (site time), from this hour */
    private const WEEKLY_SUMMARY_HOUR = 8;

    /**
     * Whether any notification address is set
     */
    public function hasRecipients(): bool
    {
        return ABTestCraft::getInstance()->getSettings()->getNotificationEmailsArray() !== [];
    }

    /**
     * Send a plain-text email to the notification addresses
     *
     * @return bool Whether it reached at least one of them
     */
    public function send(string $subject, string $body, string $context): bool
    {
        $sent = 0;
        foreach (ABTestCraft::getInstance()->getSettings()->getNotificationEmailsArray() as $email) {
            try {
                Craft::$app->getMailer()->compose()
                    ->setTo($email)
                    ->setSubject($subject)
                    ->setTextBody($body)
                    ->send();
                $sent++;
            } catch (\Throwable $e) {
                Craft::error("Failed to send {$context} email to {$email}: " . $e->getMessage(), __METHOD__);
            }
        }

        if ($sent > 0) {
            Craft::info("ABTestCraft {$context} email sent to {$sent} recipient(s)", 'abtestcraft');
        }

        return $sent > 0;
    }

    /**
     * Send the "has a winner" email, once per test
     *
     * @param array|null $stats The test's stats, if already calculated
     */
    public function checkAndNotifySignificance(Test $test, ?array $stats = null): void
    {
        if (!$test->id || $test->significanceNotifiedAt !== null) {
            return;
        }

        $stats ??= ABTestCraft::getInstance()->stats->getSummaryStats($test);

        if ($stats['isSignificant']) {
            $this->sendSignificanceEmail($test, $stats);
        }
    }

    /**
     * Email that a test has a winner
     */
    public function sendSignificanceEmail(Test $test, array $stats): void
    {
        if (!ABTestCraft::getInstance()->getSettings()->sendSignificanceEmail) {
            return;
        }

        $winner = ucfirst($stats['winner'] ?? Test::VARIANT_VARIANT);
        $subject = "Split Test '{$test->name}' has a winner: {$winner}";

        if ($this->send($subject, $this->buildSignificanceEmailBody($test, $stats, $winner), 'significance')) {
            $this->markSignificanceNotified($test);
        }
    }

    /**
     * Email that a winner was applied automatically ("When a winner is found")
     */
    public function sendWinnerApplied(Test $test, array $stats): void
    {
        $winner = ucfirst($stats['winner'] ?? 'none');

        if ($test->isServingWinner()) {
            $action = 'The test has ended and the variant is now shown to everyone on the original page.';
        } else {
            $action = 'The test has ended. Every visitor now sees the original page'
                . ($stats['winner'] === Test::VARIANT_CONTROL ? ', the winner.' : '.');
        }

        $body = str_replace(
            "has a result you can act on.\n\n",
            "has a winner, and it was applied automatically.\n{$action}\n\n",
            $this->buildSignificanceEmailBody($test, $stats, $winner)
        );

        if ($this->send("Split Test '{$test->name}' ended: {$winner} won", $body, 'winner applied')) {
            $this->markSignificanceNotified($test);
        }
    }

    /**
     * Email that the safety guardrail paused or ended a test
     */
    public function sendGuardrailTriggered(Test $test): void
    {
        $paused = $test->status === Test::STATUS_PAUSED;
        $subject = "Split Test '{$test->name}' was " . ($paused ? 'paused' : 'ended') . ' by its safety guardrail';

        $body = 'The safety guardrail ' . ($paused ? 'paused' : 'ended') . " the split test '{$test->name}'.\n\n";
        $body .= ($test->guardrailMessage ?? '') . "\n\n";
        $body .= 'Every visitor now sees the original page. ';
        $body .= $paused
            ? "The test's data is kept. Check the results, then start the test again or end it. "
                . "If you start it again with the same guardrail, it will pause again unless the results change.\n\n"
            : "The test has ended with the original as the winner.\n\n";
        $body .= 'View results: ' . $this->resultsUrl($test);

        $this->send($subject, $body, 'guardrail');
    }

    /**
     * Email that a scheduled test started
     */
    public function sendScheduledStart(Test $test): void
    {
        $body = "The split test '{$test->name}' started on schedule, and visitors are now being split.\n\n";
        $body .= 'View results: ' . $this->resultsUrl($test);

        $this->send("Split Test '{$test->name}' started", $body, 'scheduled start');
    }

    /**
     * Email that a scheduled start couldn't happen
     */
    public function sendScheduledStartFailed(Test $test, string $reason): void
    {
        $body = "The split test '{$test->name}' was scheduled to start, but couldn't:\n\n{$reason}\n\n";
        $body .= "The schedule has been cleared. Fix the problem, then start the test or schedule it again.\n\n";
        $body .= 'Edit the test: ' . UrlHelper::cpUrl("abtestcraft/tests/{$test->id}");

        $this->send("Split Test '{$test->name}' couldn't start", $body, 'scheduled start failed');
    }

    /**
     * Email that a scheduled test ended
     */
    public function sendScheduledEnd(Test $test, array $stats): void
    {
        $body = "The split test '{$test->name}' ended on schedule.\n\n";

        if ($test->winnerVariant) {
            $body .= 'Winner: ' . ucfirst($test->winnerVariant) . "\n";
            if ($test->isServingWinner()) {
                $body .= "The variant is now shown to everyone on the original page.\n";
            }
        } elseif ($stats['verdictReady']) {
            $body .= "There was no significant difference between the versions.\n";
        } else {
            $missing = [];
            if (!$stats['sampleReached']) {
                $missing[] = "its planned sample ({$stats['sampleSizeNeeded']} visitors per version)";
            }
            if (!$stats['durationReached']) {
                $missing[] = "the minimum duration ({$stats['minimumDays']} days)";
            }
            $body .= "The test ended before a result could be called: it hadn't reached "
                . implode(' or ', $missing) . ".\n";
        }

        $body .= "\n" . $this->formatRates($stats) . "\nView results: " . $this->resultsUrl($test);

        $this->send("Split Test '{$test->name}' ended", $body, 'scheduled end');
    }

    /**
     * Send the weekly summary if it's switched on and due (Monday mornings,
     * site time, once that day)
     *
     * @param DateTime|null $now The current time (for tests)
     * @return bool Whether it was sent
     */
    public function sendWeeklySummaryIfDue(?DateTime $now = null): bool
    {
        if (!ABTestCraft::getInstance()->getSettings()->sendWeeklySummary || !$this->hasRecipients()) {
            return false;
        }

        $now = (clone ($now ?? new DateTime()))->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
        $today = $now->format('Y-m-d');
        if ($now->format('N') !== '1' || (int) $now->format('G') < self::WEEKLY_SUMMARY_HOUR) {
            return false;
        }
        if (ABTestCraft::getInstance()->state->get(self::WEEKLY_SUMMARY_KEY) === $today) {
            return false;
        }

        // Claim today's summary first, so simultaneous checks don't both send it
        ABTestCraft::getInstance()->state->set(self::WEEKLY_SUMMARY_KEY, $today);

        $body = $this->buildWeeklySummary();
        if ($body === null) {
            return false; // Nothing to report
        }

        return $this->send('ABTestCraft weekly summary', $body, 'weekly summary');
    }

    /**
     * The weekly summary text, or null when there are no tests to report
     */
    public function buildWeeklySummary(): ?string
    {
        $tests = ABTestCraft::getInstance()->tests;
        $stats = ABTestCraft::getInstance()->stats;
        $weekAgo = (new DateTime())->modify('-7 days');

        $active = array_filter(
            $tests->getTestsByStatus('active'),
            fn(Test $test) => $test->status !== Test::STATUS_DRAFT || $test->scheduledStartAt !== null
        );
        $recentlyCompleted = array_filter(
            $tests->getTestsByStatus('completed'),
            fn(Test $test) => $test->endedAt !== null && $test->endedAt >= $weekAgo
        );

        if ($active === [] && $recentlyCompleted === []) {
            return null;
        }

        $body = "Your split tests this week.\n";

        if ($active !== []) {
            $body .= "\nRUNNING, PAUSED AND SCHEDULED\n";
            foreach ($active as $test) {
                $body .= "\n" . $this->summariseTest($test, $stats->getSummaryStats($test));
            }
        }

        if ($recentlyCompleted !== []) {
            $body .= "\nENDED THIS WEEK\n";
            foreach ($recentlyCompleted as $test) {
                $body .= "\n- {$test->name}: "
                    . ($test->winnerVariant ? ucfirst($test->winnerVariant) . ' won' : 'no winner')
                    . ($test->isServingWinner() ? ' (variant shown to everyone)' : '') . "\n";
            }
        }

        $body .= "\nYou get this email because the weekly summary is switched on in ABTestCraft's settings: "
            . UrlHelper::cpUrl('settings/plugins/abtestcraft');

        return $body;
    }

    /**
     * Email body for a test with a winner
     */
    private function buildSignificanceEmailBody(Test $test, array $stats, string $winner): string
    {
        $controlRate = (float) $stats['conversionRates']['control'];
        $variantRate = (float) $stats['conversionRates']['variant'];

        // Calculate relative lift (percentage improvement)
        $relativeLift = $controlRate > 0
            ? (($variantRate - $controlRate) / $controlRate) * 100
            : 0;
        $relativeLiftFormatted = ($relativeLift >= 0 ? '+' : '') . number_format($relativeLift, 1) . '%';

        // Calculate absolute difference (percentage points)
        $absoluteDiff = $variantRate - $controlRate;
        $absoluteDiffFormatted = ($absoluteDiff >= 0 ? '+' : '') . number_format($absoluteDiff, 2) . ' pp';

        $body = "Your split test '{$test->name}' has a result you can act on.\n\n";

        $body .= "WINNER: {$winner}\n";
        $body .= 'Confidence: ' . number_format($stats['confidence'] * 100, 1) . "%\n";
        $body .= "Primary goal: {$stats['primaryGoalLabel']}\n\n";

        $body .= "PERFORMANCE\n";
        $body .= "Relative lift: {$relativeLiftFormatted}\n";
        $body .= "Absolute lift: {$absoluteDiffFormatted}\n\n";

        $body .= $this->formatRates($stats) . "\n";

        $body .= "The test has reached its planned sample of {$stats['sampleSizeNeeded']} visitors per version ";
        $body .= "and has run for {$stats['daysRunning']} days.\n\n";

        $body .= 'View full results: ' . $this->resultsUrl($test);

        return $body;
    }

    private function summariseTest(Test $test, array $stats): string
    {
        if ($test->status === Test::STATUS_DRAFT) {
            $start = $test->scheduledStartAt
                ? (clone $test->scheduledStartAt)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()))
                : null;
            return "- {$test->name}: scheduled to start " . ($start?->format('M j, g:i a') ?? '') . "\n";
        }

        $line = "- {$test->name} (" . ($test->status === Test::STATUS_PAUSED ? 'paused' : 'running')
            . ", day {$stats['daysRunning']}): ";

        if ($stats['isSignificant']) {
            $line .= ucfirst((string) $stats['winner']) . ' is winning, and the result can be called.';
        } elseif ($stats['verdictReady']) {
            $line .= 'no significant difference.';
        } elseif ($stats['timeEstimate'] && !$stats['timeEstimate']['reached']) {
            $line .= "collecting data, about {$stats['timeEstimate']['daysRemaining']} more days.";
        } else {
            $line .= 'collecting data.';
        }

        $line .= "\n  Control {$stats['conversionRates']['control']}% of {$stats['visitors']['control']} visitors, "
            . "variant {$stats['conversionRates']['variant']}% of {$stats['visitors']['variant']}, "
            . 'on ' . strtolower($stats['primaryGoalLabel']);

        if ($stats['guardrail']['status'] === 'triggered') {
            $line .= "\n  The safety guardrail paused this test.";
        }
        if ($stats['srm']['detected'] ?? false) {
            $line .= "\n  Warning: visitors aren't split as configured, so results may be unreliable.";
        }

        return $line . "\n";
    }

    private function formatRates(array $stats): string
    {
        return "CONVERSION RATES (visitors who converted / visitors)\n"
            . "Control: {$stats['conversionRates']['control']}% "
            . "({$stats['convertedVisitors']['control']}/{$stats['visitors']['control']})\n"
            . "Variant: {$stats['conversionRates']['variant']}% "
            . "({$stats['convertedVisitors']['variant']}/{$stats['visitors']['variant']})\n";
    }

    /**
     * Results page link (respects the site's control panel path)
     */
    private function resultsUrl(Test $test): string
    {
        return UrlHelper::cpUrl("abtestcraft/tests/{$test->id}/results");
    }

    /**
     * Record that a test's winner email was sent, so it isn't sent again
     */
    private function markSignificanceNotified(Test $test): void
    {
        if (!$test->id) {
            return;
        }

        try {
            // Stored in UTC like every Craft date, so it reads back correctly
            $now = new DateTime();
            TestRecord::updateAll(['significanceNotifiedAt' => Db::prepareDateForDb($now)], ['id' => $test->id]);
            $test->significanceNotifiedAt = $now;
        } catch (\Throwable $e) {
            Craft::error(
                "Failed to record the winner email for test '{$test->handle}': " . $e->getMessage(),
                __METHOD__
            );
        }
    }
}
