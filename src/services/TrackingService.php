<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\db\Expression;
use yii\db\IntegrityException;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Settings;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\VisitorRecord;
use livehand\abtestcraft\ABTestCraft;
use DateTime;

/**
 * Tracking service - handles impressions and conversions
 */
class TrackingService extends Component
{
    /** Visitor goal slot for a visitor's first conversion of any goal type */
    public const ANY_GOAL = '*';

    private const COUNTER_COLUMNS = ['impressions', 'conversions', 'visitors', 'convertedVisitors'];

    /**
     * Record an impression for a test
     */
    public function recordImpression(Test $test, string $variant): bool
    {
        try {
            $this->incrementDailyStat((int) $test->id, $variant, null, 'impressions');
        } catch (\Throwable $e) {
            Craft::error(
                "Failed to save impression record for test '{$test->handle}': " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }

        return true;
    }

    /**
     * Count a newly assigned visitor in today's stats. Visitor counts live in
     * the daily stats (not just the visitor records), so results don't change
     * when old visitor records are cleaned up.
     */
    public function recordNewVisitor(Test $test, string $variant): bool
    {
        try {
            $this->incrementDailyStat((int) $test->id, $variant, null, 'visitors');
        } catch (\Throwable $e) {
            Craft::error(
                "Failed to count new visitor for test '{$test->handle}': " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }

        return true;
    }

    /**
     * Record a conversion for a test.
     *
     * Results are per visitor: each visitor counts once per goal type, and
     * once for "any goal" on their first conversion, whatever the counting
     * mode. The counting mode only decides what the raw "conversions" total
     * counts: one per goal type (default), the first conversion only, or every
     * conversion. Each claim is an atomic insert on a unique row, so
     * simultaneous requests can't count the same thing twice.
     */
    public function recordConversion(Test $test, string $conversionType, ?int $goalId = null): bool
    {
        // Get visitor record
        $visitorRecord = ABTestCraft::getInstance()->assignment->getVisitorRecord($test);

        if (!$visitorRecord) {
            Craft::warning(
                "Split Test: Cannot record conversion - visitor not found for test '{$test->handle}'",
                __METHOD__
            );
            return false;
        }

        $testId = (int) $test->id;
        $visitorId = $visitorRecord->visitorId;
        $variant = $visitorRecord->variant;

        $firstOfType = $this->claimConversionSlot($testId, $visitorId, $conversionType);
        $firstOfAny = $this->claimConversionSlot($testId, $visitorId, self::ANY_GOAL);

        $mode = ABTestCraft::getInstance()->getSettings()->conversionCountingMode ?? Settings::COUNTING_PER_GOAL_TYPE;
        $countConversion = match ($mode) {
            Settings::COUNTING_FIRST_ONLY => $firstOfAny,
            Settings::COUNTING_UNLIMITED => true,
            default => $firstOfType,
        };

        if (!$countConversion && !$firstOfType && !$firstOfAny) {
            return true; // Already counted
        }

        // Atomic increments, deliberately not in a transaction: a row inserted
        // in an open transaction isn't visible to concurrent requests, which
        // would then insert duplicates
        try {
            if ($countConversion) {
                $this->incrementDailyStat($testId, $variant, null, 'conversions');
                $this->incrementDailyStat($testId, $variant, $conversionType, 'conversions');
            }
            if ($firstOfType) {
                $this->incrementDailyStat($testId, $variant, $conversionType, 'convertedVisitors');
            }
            if ($firstOfAny) {
                $this->incrementDailyStat($testId, $variant, null, 'convertedVisitors');
            }
        } catch (\Throwable $e) {
            // Not counted, so free the claims and let a later attempt count it
            if ($firstOfType) {
                $this->releaseConversionSlot($testId, $visitorId, $conversionType);
            }
            if ($firstOfAny) {
                $this->releaseConversionSlot($testId, $visitorId, self::ANY_GOAL);
            }
            Craft::error(
                "Failed to record conversion for test '{$test->handle}': " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }

        // Visitor-level record (converted flag, latest conversion) last; the
        // conversion is already counted if this fails, so just log it
        $visitorRecord->converted = true;
        $visitorRecord->conversionType = $conversionType;
        $visitorRecord->goalId = $goalId;
        $visitorRecord->dateConverted = Db::prepareDateForDb(new DateTime());

        if (!$visitorRecord->save()) {
            Craft::warning(
                "Conversion counted for test '{$test->handle}' but visitor record not updated: " .
                json_encode($visitorRecord->getErrors()),
                __METHOD__
            );
        }

        // Check if test has reached significance (outside transaction)
        $this->checkSignificance($test);

        return true;
    }

    /**
     * Claim a conversion slot for a visitor: a goal type, or '*' for any
     * goal. Returns false if it was already claimed. The unique index on
     * (testId, visitorId, goalType) makes this safe under concurrency.
     */
    private function claimConversionSlot(int $testId, string $visitorId, string $slot): bool
    {
        $table = '{{%abtestcraft_visitor_goals}}';
        $key = ['testId' => $testId, 'visitorId' => $visitorId, 'goalType' => $slot];

        // Cheap check first, so the common duplicate case doesn't log a DB error
        if ((new Query())->from($table)->where($key)->exists()) {
            return false;
        }

        $now = Db::prepareDateForDb(new DateTime());
        try {
            Craft::$app->getDb()->createCommand()->insert($table, $key + [
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (IntegrityException) {
            return false; // Another request claimed it at the same moment
        }

        return true;
    }

    private function releaseConversionSlot(int $testId, string $visitorId, string $slot): void
    {
        try {
            Craft::$app->getDb()->createCommand()->delete('{{%abtestcraft_visitor_goals}}', [
                'testId' => $testId,
                'visitorId' => $visitorId,
                'goalType' => $slot,
            ])->execute();
        } catch (\Throwable $e) {
            Craft::error("Failed to release conversion slot: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Atomically add 1 to one of today's counters for a variant.
     *
     * A read-modify-write through the ActiveRecord lost counts under
     * concurrent traffic (about 26-48% of impressions at 20 concurrent
     * requests). The increment is a single UPDATE; a missing row is created
     * under a mutex and re-checked, so simultaneous first hits of the day
     * don't create duplicate rows (the unique index can't prevent those when
     * goalType is NULL).
     *
     * @param string $column 'impressions', 'conversions', 'visitors' or 'convertedVisitors'
     */
    private function incrementDailyStat(int $testId, string $variant, ?string $goalType, string $column): void
    {
        if (!in_array($column, self::COUNTER_COLUMNS, true)) {
            throw new \InvalidArgumentException("Invalid stats column: {$column}");
        }

        $today = (new DateTime())->format('Y-m-d');
        $condition = ['testId' => $testId, 'date' => $today, 'variant' => $variant, 'goalType' => $goalType];

        if ($this->incrementExistingDailyStat($condition, $column)) {
            return;
        }

        $mutex = Craft::$app->getMutex();
        $lockName = 'abtestcraft-daily-stats-' . md5(json_encode($condition));

        if (!$mutex->acquire($lockName, 5)) {
            throw new \RuntimeException("Could not acquire stats lock for test {$testId}");
        }

        try {
            // Another request may have created the row while we waited
            if ($this->incrementExistingDailyStat($condition, $column)) {
                return;
            }

            // Audit columns set explicitly so this doesn't depend on the
            // connection using Craft's command class
            $now = Db::prepareDateForDb(new DateTime());
            $counters = array_fill_keys(self::COUNTER_COLUMNS, 0);
            $counters[$column] = 1;
            Craft::$app->getDb()->createCommand()->insert('{{%abtestcraft_daily_stats}}', $condition + $counters + [
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * Single-statement increment of an existing daily stats row.
     *
     * @phpstan-impure
     * @return bool Whether a row existed (and was incremented)
     */
    private function incrementExistingDailyStat(array $condition, string $column): bool
    {
        return Craft::$app->getDb()->createCommand()
            ->update('{{%abtestcraft_daily_stats}}', [
                $column => new Expression("[[{$column}]] + 1"),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ], $condition)
            ->execute() > 0;
    }

    /**
     * Record conversion by test handle (used by tracking endpoint)
     */
    public function recordConversionByHandle(string $testHandle, string $conversionType, ?int $goalId = null): bool
    {
        $test = ABTestCraft::getInstance()->tests->getTestByHandle($testHandle);

        if (!$test || !$test->isRunning()) {
            return false;
        }

        // Smart-mode form goals are counted on the server when the form plugin
        // accepts the submission (FormSubmissionService). Refuse browser reports
        // of them, including from pages cached with an older tracking script,
        // so a submission is never counted twice.
        if ($conversionType === Goal::TYPE_FORM) {
            $formGoal = ABTestCraft::getInstance()->goals->getGoalByTestAndType((int) $test->id, Goal::TYPE_FORM);
            if ($formGoal && $formGoal->isSmartMode()) {
                return false;
            }
        }

        return $this->recordConversion($test, $conversionType, $goalId);
    }

    /**
     * Get total impressions for a test
     */
    public function getTotalImpressions(Test $test): array
    {
        $stats = (new Query())
            ->select(['variant', 'SUM(impressions) as total'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id])
            ->groupBy(['variant'])
            ->all();

        $result = ['control' => 0, 'variant' => 0];

        foreach ($stats as $stat) {
            $result[$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * Get total conversions for a test
     */
    public function getTotalConversions(Test $test): array
    {
        $stats = (new Query())
            ->select(['variant', 'SUM(conversions) as total'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id])
            ->andWhere(['goalType' => null])
            ->groupBy(['variant'])
            ->all();

        $result = ['control' => 0, 'variant' => 0];

        foreach ($stats as $stat) {
            $result[$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * Get unique visitors for a test
     */
    public function getUniqueVisitors(Test $test): array
    {
        $stats = (new Query())
            ->select(['variant', 'COUNT(*) as total'])
            ->from('{{%abtestcraft_visitors}}')
            ->where(['testId' => $test->id])
            ->groupBy(['variant'])
            ->all();

        $result = ['control' => 0, 'variant' => 0];

        foreach ($stats as $stat) {
            $result[$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * After a conversion: the safety guardrail, "When a winner is found" and
     * the winner email (see TestRulesService)
     */
    private function checkSignificance(Test $test): void
    {
        try {
            ABTestCraft::getInstance()->rules->checkTest($test);
        } catch (\Throwable $e) {
            // The conversion is already counted
            Craft::error("Checking test '{$test->handle}' after a conversion failed: " . $e->getMessage(), __METHOD__);
        }
    }
}
