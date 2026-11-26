<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use DateTime;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\helpers\Statistics;
use livehand\abtestcraft\models\Test;

/**
 * Stats service - calculates test results and statistical significance.
 *
 * Results are per visitor: the conversion rate is the share of visitors
 * assigned to a version who converted on the test's primary goal (any goal
 * when none is set). Each visitor is one trial, which is what the
 * chi-squared test assumes. Page views and raw conversion totals are
 * reported alongside but don't drive the result.
 *
 * A winner is only called once each version has reached the planned sample
 * size and the test has run the minimum number of days. Until then the
 * confidence shown is provisional: checking a result over and over and
 * stopping the first time it looks significant produces false winners.
 */
class StatsService extends Component
{
    /** Below this many visitors in a version, significance isn't calculated */
    private const MIN_VISITORS = 10;

    /** Sample ratio mismatch is flagged below this p-value... */
    private const SRM_P_VALUE = 0.001;

    /** ...once at least this many visitors are in the test */
    private const SRM_MIN_VISITORS = 100;

    /** Planned visitors per version when there's no baseline rate yet */
    private const DEFAULT_SAMPLE_SIZE = 1000;

    private const CACHE_PREFIX = 'abtestcraft_stats_v2_';

    private const VARIANTS = [Test::VARIANT_CONTROL, Test::VARIANT_VARIANT];

    /**
     * Summary stats for several tests from one query (Tests index page)
     *
     * @param Test[] $tests
     * @return array<int, array> Keyed by test ID
     */
    public function getBatchTestStats(array $tests): array
    {
        if (empty($tests)) {
            return [];
        }

        $totals = $this->loadTotals(array_map(fn(Test $test) => (int) $test->id, $tests));

        $result = [];
        foreach ($tests as $test) {
            $result[$test->id] = $this->buildStats($test, $totals[$test->id] ?? []);
        }

        return $result;
    }

    /**
     * Full stats for a test (results page, console, notifications).
     * Cached for completed tests, whose numbers no longer change.
     */
    public function getTestStats(Test $test): array
    {
        $cacheKey = self::CACHE_PREFIX . $test->id;
        $fingerprint = $this->getFingerprint($test);

        if ($test->isCompleted() && $test->id !== null) {
            $cached = Craft::$app->getCache()->get($cacheKey);
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
                return $cached['stats'];
            }
        }

        $totals = $this->loadTotals([(int) $test->id]);
        $stats = $this->buildStats($test, $totals[$test->id] ?? []);
        $stats['dailyStats'] = $this->getDailyStats($test);

        if ($test->isCompleted() && $test->id !== null) {
            Craft::$app->getCache()->set($cacheKey, ['fingerprint' => $fingerprint, 'stats' => $stats], 0);
        }

        return $stats;
    }

    /**
     * Invalidate cached stats for a test
     */
    public function invalidateStatsCache(int $testId): void
    {
        Craft::$app->getCache()->delete(self::CACHE_PREFIX . $testId);
    }

    /**
     * Estimate of how long until a winner can be called (null when there's
     * too little data to estimate)
     */
    public function getTimeEstimate(Test $test): ?array
    {
        return $this->getTestStats($test)['timeEstimate'];
    }

    /**
     * Sums of the daily stats per test, version and goal type ('*' for the
     * all-goals row)
     *
     * @param int[] $testIds
     * @return array<int, array<string, array<string, array<string, int>>>> testId => variant => goal => counts
     */
    private function loadTotals(array $testIds): array
    {
        $rows = (new Query())
            ->select([
                'testId',
                'variant',
                'goalType',
                'impressions' => 'SUM([[impressions]])',
                'conversions' => 'SUM([[conversions]])',
                'visitors' => 'SUM([[visitors]])',
                'convertedVisitors' => 'SUM([[convertedVisitors]])',
            ])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $testIds])
            ->groupBy(['testId', 'variant', 'goalType'])
            ->all();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['testId']][$row['variant']][$row['goalType'] ?? TrackingService::ANY_GOAL] = [
                'impressions' => (int) $row['impressions'],
                'conversions' => (int) $row['conversions'],
                'visitors' => (int) $row['visitors'],
                'convertedVisitors' => (int) $row['convertedVisitors'],
            ];
        }

        return $totals;
    }

    /**
     * Results for a test from its daily stats totals
     */
    private function buildStats(Test $test, array $totals): array
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        $primaryKey = $test->primaryGoalType ?? TrackingService::ANY_GOAL;

        $visitors = $pageViews = $conversions = $converted = $conversionRates = $pagesPerVisitor = [];
        foreach (self::VARIANTS as $variant) {
            $all = $totals[$variant][TrackingService::ANY_GOAL] ?? [];
            $visitors[$variant] = $all['visitors'] ?? 0;
            $pageViews[$variant] = $all['impressions'] ?? 0;
            $conversions[$variant] = $all['conversions'] ?? 0;
            $converted[$variant] = $totals[$variant][$primaryKey]['convertedVisitors'] ?? 0;
            $conversionRates[$variant] = $this->calculateConversionRate($visitors[$variant], $converted[$variant]);
            $pagesPerVisitor[$variant] = $visitors[$variant] > 0
                ? round($pageViews[$variant] / $visitors[$variant], 1)
                : 0;
        }

        $significance = $this->calculateSignificance(
            $visitors[Test::VARIANT_CONTROL],
            $converted[Test::VARIANT_CONTROL],
            $visitors[Test::VARIANT_VARIANT],
            $converted[Test::VARIANT_VARIANT]
        );

        // The planned sample, and whether it (and the minimum duration) is met
        $sampleSizeNeeded = $this->calculateSampleSizeNeeded($conversionRates[Test::VARIANT_CONTROL]);
        $daysRunning = $this->getDaysRunning($test);
        $minimumDays = $settings->minimumTestDays;
        $sampleReached = min($visitors) >= $sampleSizeNeeded;
        $durationReached = $daysRunning >= $minimumDays;
        $verdictReady = $sampleReached && $durationReached;

        $isSignificant = $verdictReady && $significance['confidence'] >= $settings->significanceThreshold;
        $totalVisitors = array_sum($visitors);

        $stats = [
            'primaryGoalType' => $test->primaryGoalType,
            'primaryGoalLabel' => $test->getPrimaryGoalLabel(),
            'visitors' => $visitors,
            'totalVisitors' => $totalVisitors,
            'convertedVisitors' => $converted,
            'conversionRates' => $conversionRates,
            'confidenceIntervals' => [
                Test::VARIANT_CONTROL => $this->calculateConfidenceInterval(
                    $conversionRates[Test::VARIANT_CONTROL] / 100,
                    $visitors[Test::VARIANT_CONTROL]
                ),
                Test::VARIANT_VARIANT => $this->calculateConfidenceInterval(
                    $conversionRates[Test::VARIANT_VARIANT] / 100,
                    $visitors[Test::VARIANT_VARIANT]
                ),
            ],
            // Every render of a tested page (and its child pages)
            'pageViews' => $pageViews,
            'pagesPerVisitor' => $pagesPerVisitor,
            // Raw conversion events, as the counting mode counts them
            'conversions' => $conversions,
            'confidence' => $significance['confidence'],
            'threshold' => $settings->significanceThreshold,
            'pValue' => $significance['pValue'],
            'chiSquared' => $significance['chiSquared'],
            'isSignificant' => $isSignificant,
            'improvement' => $this->calculateImprovement(
                $conversionRates[Test::VARIANT_CONTROL],
                $conversionRates[Test::VARIANT_VARIANT]
            ),
            'winner' => $this->determineWinner($conversionRates, $isSignificant),
            'verdictReady' => $verdictReady,
            'sampleReached' => $sampleReached,
            'durationReached' => $durationReached,
            'sampleSizeNeeded' => $sampleSizeNeeded,
            'daysRunning' => $daysRunning,
            'minimumDays' => $minimumDays,
            'progressPercent' => $sampleSizeNeeded > 0
                ? (int) min(100, round(min($visitors) / $sampleSizeNeeded * 100))
                : 0,
            'srm' => $this->checkSampleRatio($test, $visitors),
            'goalStats' => $this->getGoalStats($totals, $visitors),
        ];

        $stats['timeEstimate'] = $this->estimateTime($test, $stats);

        return $stats;
    }

    /**
     * Per goal type: visitors who converted on it, their rate, and the raw
     * conversion count
     */
    private function getGoalStats(array $totals, array $visitors): array
    {
        $goalStats = [];
        foreach (self::VARIANTS as $variant) {
            foreach ($totals[$variant] ?? [] as $goalKey => $values) {
                if ($goalKey === TrackingService::ANY_GOAL) {
                    continue;
                }
                $goalStats[$goalKey][$variant] = [
                    'convertedVisitors' => $values['convertedVisitors'],
                    'rate' => $this->calculateConversionRate($visitors[$variant], $values['convertedVisitors']),
                    'conversions' => $values['conversions'],
                ];
            }
        }

        $empty = ['convertedVisitors' => 0, 'rate' => 0.0, 'conversions' => 0];
        foreach ($goalStats as $goalKey => $byVariant) {
            $goalStats[$goalKey] += [Test::VARIANT_CONTROL => $empty, Test::VARIANT_VARIANT => $empty];
        }

        return $goalStats;
    }

    /**
     * Sample ratio mismatch: visitors split noticeably differently from the
     * configured traffic split. Usually caching, redirects or bots, and it
     * makes the comparison unreliable.
     */
    private function checkSampleRatio(Test $test, array $visitors): ?array
    {
        $total = array_sum($visitors);
        $pValue = Statistics::sampleRatioMismatchPValue(
            $visitors[Test::VARIANT_CONTROL],
            $visitors[Test::VARIANT_VARIANT],
            $test->trafficSplit
        );

        if ($pValue === null || $total === 0) {
            return null;
        }

        return [
            'detected' => $total >= self::SRM_MIN_VISITORS && $pValue < self::SRM_P_VALUE,
            'pValue' => $pValue,
            'expectedVariantPercent' => $test->trafficSplit,
            'actualVariantPercent' => round($visitors[Test::VARIANT_VARIANT] / $total * 100, 1),
        ];
    }

    /**
     * Days until a winner can be called: the longer of reaching the planned
     * sample at the current traffic and reaching the minimum duration
     */
    private function estimateTime(Test $test, array $stats): ?array
    {
        if ($stats['verdictReady']) {
            return ['reached' => true, 'daysRemaining' => 0];
        }

        if ($stats['totalVisitors'] < self::MIN_VISITORS || $test->startedAt === null) {
            return null;
        }

        $end = $test->endedAt ?? new DateTime();
        $elapsedDays = max(1.0, ($end->getTimestamp() - $test->startedAt->getTimestamp()) / 86400);

        $daysForSample = 0;
        foreach ($stats['visitors'] as $count) {
            $perDay = $count / $elapsedDays;
            if ($perDay <= 0) {
                return null;
            }
            $daysForSample = max($daysForSample, (int) ceil(max(0, $stats['sampleSizeNeeded'] - $count) / $perDay));
        }

        $daysForDuration = max(0, $stats['minimumDays'] - $stats['daysRunning']);

        return [
            'reached' => false,
            'daysRemaining' => max($daysForSample, $daysForDuration),
            'visitorsNeeded' => $stats['sampleSizeNeeded'] * 2,
            'currentVisitors' => $stats['totalVisitors'],
            'sampleSizePerVariant' => $stats['sampleSizeNeeded'],
            'dailyVisitors' => (int) round($stats['totalVisitors'] / $elapsedDays),
        ];
    }

    /**
     * Whole days since the test started (to its end, if completed)
     */
    private function getDaysRunning(Test $test): int
    {
        if ($test->startedAt === null) {
            return 0;
        }

        $end = $test->endedAt ?? new DateTime();

        return max(0, intdiv($end->getTimestamp() - $test->startedAt->getTimestamp(), 86400));
    }

    /**
     * Per-day visitors, page views and visitors converting on the primary
     * goal, with the cumulative conversion rate up to each day (daily rates
     * jump around, and conversions often happen days after the visit)
     */
    private function getDailyStats(Test $test): array
    {
        $primaryType = $test->primaryGoalType;

        $rows = (new Query())
            ->select(['date', 'variant', 'goalType', 'impressions', 'visitors', 'convertedVisitors'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id])
            ->andWhere($primaryType === null
                ? ['goalType' => null]
                : ['or', ['goalType' => null], ['goalType' => $primaryType]])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        $empty = ['visitors' => 0, 'pageViews' => 0, 'converted' => 0, 'rate' => 0.0];
        $days = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            $days[$date] ??= ['date' => $date, 'control' => $empty, 'variant' => $empty];
            $variant = $row['variant'];

            if ($row['goalType'] === null) {
                $days[$date][$variant]['visitors'] += (int) $row['visitors'];
                $days[$date][$variant]['pageViews'] += (int) $row['impressions'];
            }
            if ($row['goalType'] === $primaryType) {
                $days[$date][$variant]['converted'] += (int) $row['convertedVisitors'];
            }
        }

        $cumulative = array_fill_keys(self::VARIANTS, ['visitors' => 0, 'converted' => 0]);
        foreach ($days as $date => $day) {
            foreach (self::VARIANTS as $variant) {
                $cumulative[$variant]['visitors'] += $day[$variant]['visitors'];
                $cumulative[$variant]['converted'] += $day[$variant]['converted'];
                $days[$date][$variant]['rate'] = $this->calculateConversionRate(
                    $cumulative[$variant]['visitors'],
                    $cumulative[$variant]['converted']
                );
            }
        }

        return array_values($days);
    }

    /**
     * Conversion rate as a percentage
     */
    private function calculateConversionRate(int $visitors, int $converted): float
    {
        if ($visitors === 0) {
            return 0.0;
        }

        return round(($converted / $visitors) * 100, 2);
    }

    /**
     * Pearson's chi-squared test on a 2x2 table of converted / not converted
     * visitors in each version, with an exact p-value (1 degree of freedom).
     * Each visitor is one independent trial.
     *
     * @return array{confidence: float, chiSquared: float, pValue: float}
     */
    private function calculateSignificance(
        int $controlVisitors,
        int $controlConverted,
        int $variantVisitors,
        int $variantConverted
    ): array {
        $none = ['confidence' => 0.0, 'chiSquared' => 0.0, 'pValue' => 1.0];

        if ($controlVisitors < self::MIN_VISITORS || $variantVisitors < self::MIN_VISITORS) {
            return $none;
        }

        $chiSquared = Statistics::chiSquared2x2(
            $controlVisitors,
            $controlConverted,
            $variantVisitors,
            $variantConverted
        );
        if ($chiSquared === null) {
            return $none;
        }

        $confidence = $this->chiSquaredToConfidence($chiSquared);
        $pValue = 1 - $confidence;

        return [
            'confidence' => round($confidence, 4),
            'chiSquared' => round($chiSquared, 4),
            'pValue' => $pValue,
        ];
    }

    /**
     * Confidence level (1 - p) for a chi-squared statistic with 1 degree of
     * freedom
     */
    private function chiSquaredToConfidence(float $chiSquared): float
    {
        return 1 - Statistics::chiSquaredPValue($chiSquared);
    }

    /**
     * 95% confidence interval for a conversion rate, in percent
     */
    private function calculateConfidenceInterval(float $rate, int $n): array
    {
        $interval = Statistics::proportionInterval($rate, $n);

        return [
            'lower' => round($interval['lower'] * 100, 1),
            'upper' => round($interval['upper'] * 100, 1),
            'margin' => round($interval['margin'] * 100, 1),
        ];
    }

    /**
     * Relative improvement of the variant over the control, in percent.
     * Null when the control rate is 0 (no baseline to compare with).
     */
    private function calculateImprovement(float $controlRate, float $variantRate): ?float
    {
        if ($controlRate === 0.0) {
            return null;
        }
        return round((($variantRate - $controlRate) / $controlRate) * 100, 2);
    }

    /**
     * The winning version, once the result is significant
     */
    private function determineWinner(array $conversionRates, bool $isSignificant): ?string
    {
        if (!$isSignificant) {
            return null;
        }

        if ($conversionRates[Test::VARIANT_VARIANT] > $conversionRates[Test::VARIANT_CONTROL]) {
            return Test::VARIANT_VARIANT;
        } elseif ($conversionRates[Test::VARIANT_CONTROL] > $conversionRates[Test::VARIANT_VARIANT]) {
            return Test::VARIANT_CONTROL;
        }

        return null;
    }

    /**
     * Visitors needed in each version to detect the configured minimum
     * detectable effect at the configured confidence level, with 80% power
     *
     * @param float $baselineRate Control conversion rate in percent
     */
    private function calculateSampleSizeNeeded(float $baselineRate): int
    {
        if ($baselineRate <= 0) {
            return self::DEFAULT_SAMPLE_SIZE;
        }

        $settings = ABTestCraft::getInstance()->getSettings();

        try {
            return Statistics::sampleSizePerGroup(
                $baselineRate / 100,
                $settings->minimumDetectableEffect,
                $settings->significanceThreshold
            );
        } catch (\InvalidArgumentException) {
            // A rate so close to 100% that the effect can't be measured
            return self::DEFAULT_SAMPLE_SIZE;
        }
    }

    /**
     * What a completed test's cached stats depend on besides its data
     */
    private function getFingerprint(Test $test): string
    {
        $settings = ABTestCraft::getInstance()->getSettings();

        return md5(implode('|', [
            $test->primaryGoalType ?? '*',
            $test->trafficSplit,
            $test->endedAt?->getTimestamp() ?? '',
            $settings->significanceThreshold,
            $settings->minimumDetectableEffect,
            $settings->minimumTestDays,
        ]));
    }
}
