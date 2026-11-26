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
     * Record a conversion for a test, de-duplicated per the counting mode
     */
    public function recordConversion(Test $test, string $conversionType, ?int $goalId = null): bool
    {
        // Get visitor record
        $visitorRecord = ABTestCraft::getInstance()->assignment->getVisitorRecord($test);

        if (!$visitorRecord) {
            Craft::warning("Split Test: Cannot record conversion - visitor not found for test '{$test->handle}'", __METHOD__);
            return false;
        }

        // De-duplicate by counting mode with an atomic claim on a unique
        // (test, visitor, slot) row: the goal type ("one per goal type",
        // the default) or '*' ("first conversion only"). Unlimited mode
        // counts everything. This also stops simultaneous requests from
        // counting the same conversion twice.
        $mode = ABTestCraft::getInstance()->getSettings()->conversionCountingMode ?? Settings::COUNTING_PER_GOAL_TYPE;
        $slot = match ($mode) {
            Settings::COUNTING_FIRST_ONLY => '*',
            Settings::COUNTING_UNLIMITED => null,
            default => $conversionType,
        };

        if ($slot !== null && !$this->claimConversionSlot((int) $test->id, $visitorRecord->visitorId, $slot)) {
            return true; // Already counted under the counting mode rules
        }

        // Count it. Daily totals (goalType null, for backward compatibility)
        // and per-goal-type stats use atomic increments, deliberately not in a
        // transaction: a row inserted in an open transaction isn't visible to
        // concurrent requests, which would then insert duplicates.
        try {
            $this->incrementDailyStat((int) $test->id, $visitorRecord->variant, null, 'conversions');
            $this->incrementDailyStat((int) $test->id, $visitorRecord->variant, $conversionType, 'conversions');
        } catch (\Throwable $e) {
            // Not counted, so free the slot and let a later attempt count it
            if ($slot !== null) {
                $this->releaseConversionSlot((int) $test->id, $visitorRecord->visitorId, $slot);
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
        $visitorRecord->dateConverted = (new DateTime())->format('Y-m-d H:i:s');

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
     * Claim a conversion slot for a visitor. Returns false if it was already
     * claimed (the conversion was counted before). The unique index on
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
     * Atomically add 1 to today's impressions or conversions for a variant.
     *
     * A read-modify-write through the ActiveRecord lost counts under
     * concurrent traffic (about 26-48% of impressions at 20 concurrent
     * requests). The increment is a single UPDATE; a missing row is created
     * under a mutex and re-checked, so simultaneous first hits of the day
     * don't create duplicate rows (the unique index can't prevent those when
     * goalType is NULL).
     *
     * @param string $column 'impressions' or 'conversions'
     */
    private function incrementDailyStat(int $testId, string $variant, ?string $goalType, string $column): void
    {
        if (!in_array($column, ['impressions', 'conversions'], true)) {
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
            Craft::$app->getDb()->createCommand()->insert('{{%abtestcraft_daily_stats}}', $condition + [
                'impressions' => $column === 'impressions' ? 1 : 0,
                'conversions' => $column === 'conversions' ? 1 : 0,
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
     * Check if test has reached statistical significance and notify if enabled
     * Delegates to NotificationService for actual notification handling
     */
    private function checkSignificance(Test $test): void
    {
        ABTestCraft::getInstance()->notifications->checkAndNotifySignificance($test);
    }
}
