<?php

declare(strict_types=1);

namespace livehand\abtestcraft\helpers;

/**
 * Pure statistics functions used for test results.
 *
 * No Craft dependencies, so they can be unit tested directly.
 */
final class Statistics
{
    /**
     * Complementary error function, erfc(x) = 1 - erf(x).
     *
     * Chebyshev approximation from Numerical Recipes (erfcc), with a
     * fractional error below 1.2e-7 everywhere: far more precise than the
     * percentages shown to people.
     */
    public static function erfc(float $x): float
    {
        $z = abs($x);
        $t = 1 / (1 + 0.5 * $z);
        $poly = -1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418
            + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587
            + $t * (-0.82215223 + $t * 0.17087277))))))));
        $result = $t * exp(-$z * $z + $poly);

        return $x >= 0 ? $result : 2 - $result;
    }

    /**
     * Two-sided p-value of a chi-squared statistic with 1 degree of freedom.
     *
     * With 1 degree of freedom, chi-squared is the square of a standard normal
     * variable, so p = P(|Z| >= sqrt(chi2)) = erfc(sqrt(chi2 / 2)).
     */
    public static function chiSquaredPValue(float $chiSquared): float
    {
        if ($chiSquared <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, self::erfc(sqrt($chiSquared / 2))));
    }

    /**
     * Pearson's chi-squared statistic for a 2x2 table of converted / not
     * converted visitors in two groups. Null when a cell's expected count is
     * zero (no conversions, or everyone converted), where the test is undefined.
     */
    public static function chiSquared2x2(int $visitorsA, int $convertedA, int $visitorsB, int $convertedB): ?float
    {
        $total = $visitorsA + $visitorsB;
        $converted = $convertedA + $convertedB;
        $notConverted = $total - $converted;

        if ($visitorsA <= 0 || $visitorsB <= 0 || $converted <= 0 || $notConverted <= 0) {
            return null;
        }

        $observed = [
            [$convertedA, $visitorsA - $convertedA],
            [$convertedB, $visitorsB - $convertedB],
        ];
        $rowTotals = [$visitorsA, $visitorsB];
        $columnTotals = [$converted, $notConverted];

        $chiSquared = 0.0;
        foreach ($observed as $row => $cells) {
            foreach ($cells as $column => $count) {
                $expected = $rowTotals[$row] * $columnTotals[$column] / $total;
                $chiSquared += ($count - $expected) ** 2 / $expected;
            }
        }

        return $chiSquared;
    }

    /**
     * Inverse of the standard normal cumulative distribution function.
     *
     * Peter Acklam's rational approximation (relative error below 1.15e-9).
     *
     * @param float $p Probability, strictly between 0 and 1
     */
    public static function normalQuantile(float $p): float
    {
        if ($p <= 0 || $p >= 1) {
            throw new \InvalidArgumentException('Probability must be between 0 and 1.');
        }

        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
            1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
            6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
            -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
            3.754408661907416e+00];

        $low = 0.02425;
        $high = 1 - $low;

        if ($p < $low) {
            $q = sqrt(-2 * log($p));
            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        if ($p > $high) {
            $q = sqrt(-2 * log(1 - $p));
            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        $q = $p - 0.5;
        $r = $q * $q;
        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
            / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }

    /**
     * Visitors needed in each group to detect a relative change in a
     * conversion rate, with a two-sided test at the given confidence level and
     * power (standard two-proportion formula).
     *
     * @param float $baselineRate Control conversion rate as a proportion (0-1)
     * @param float $relativeEffect Smallest relative change to detect (0.10 = 10%)
     * @param float $confidence Confidence level (0.95 = 95%)
     * @param float $power Statistical power (0.80 = 80%)
     */
    public static function sampleSizePerGroup(
        float $baselineRate,
        float $relativeEffect,
        float $confidence,
        float $power = 0.80,
    ): int {
        $p1 = $baselineRate;
        $p2 = min(0.9999, $baselineRate * (1 + $relativeEffect));
        $delta = $p2 - $p1;

        if ($p1 <= 0 || $p1 >= 1 || $delta <= 0) {
            throw new \InvalidArgumentException('Baseline rate and effect must give two different rates.');
        }

        $zAlpha = self::normalQuantile(1 - (1 - $confidence) / 2);
        $zBeta = self::normalQuantile($power);
        $pooled = ($p1 + $p2) / 2;

        $numerator = $zAlpha * sqrt(2 * $pooled * (1 - $pooled))
            + $zBeta * sqrt($p1 * (1 - $p1) + $p2 * (1 - $p2));

        return (int) ceil(($numerator / $delta) ** 2);
    }

    /**
     * Mixture sequential probability ratio test (mSPRT) for a difference
     * between two conversion rates, with the normal approximation used for
     * continuously monitored experiments.
     *
     * Unlike a fixed-sample test, it can be checked after every visitor:
     * declaring a difference whenever the ratio reaches 1/alpha keeps the
     * chance of ever raising a false alarm at or below alpha, however often
     * it's checked. Used by the safety guardrail, which only acts when the
     * variant is the worse one, so its false-alarm rate is at most alpha.
     *
     * @param float $tau Spread of the mixing distribution: the size of
     *     difference (as a proportion) the test is most sensitive to
     * @return float|null The likelihood ratio, or null when either group has
     *     no variance yet (no conversions, or all converted)
     */
    public static function mixtureSprtRatio(
        int $visitorsA,
        int $convertedA,
        int $visitorsB,
        int $convertedB,
        float $tau,
    ): ?float {
        if ($visitorsA <= 0 || $visitorsB <= 0 || $tau <= 0) {
            return null;
        }

        $rateA = $convertedA / $visitorsA;
        $rateB = $convertedB / $visitorsB;
        $variance = $rateA * (1 - $rateA) / $visitorsA + $rateB * (1 - $rateB) / $visitorsB;
        if ($variance <= 0) {
            return null;
        }

        $difference = $rateB - $rateA;
        $tauSquared = $tau * $tau;
        $exponent = $difference ** 2 * $tauSquared / (2 * $variance * ($variance + $tauSquared));

        // Cap the exponent: past ~700 exp() overflows, and it's far beyond any threshold
        return sqrt($variance / ($variance + $tauSquared)) * exp(min($exponent, 700.0));
    }

    /**
     * Roughly how many visitors per group a one-sided test at the given
     * confidence needs to detect a relative drop, with 80% power. A lower
     * bound for the guardrail, whose continuously checked test needs more.
     *
     * @param float $baselineRate Control conversion rate as a proportion
     * @param float $relativeDrop Drop to detect (0.25 = 25% lower)
     * @param float $confidence One-sided confidence (0.99 = 99%)
     */
    public static function sampleSizeForDrop(float $baselineRate, float $relativeDrop, float $confidence): ?int
    {
        if ($baselineRate <= 0 || $baselineRate >= 1 || $relativeDrop <= 0 || $relativeDrop >= 1) {
            return null;
        }

        // The same calculation as for an increase from the lower rate, at the
        // two-sided confidence with the same one-sided error
        try {
            return self::sampleSizePerGroup(
                $baselineRate * (1 - $relativeDrop),
                $relativeDrop / (1 - $relativeDrop),
                1 - 2 * (1 - $confidence)
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Sample ratio mismatch: p-value of a chi-squared goodness-of-fit test of
     * the visitors in each group against the configured split. A tiny p-value
     * means the split itself is off (caching, redirects, bots), which makes the
     * comparison unreliable. Null when it can't be tested.
     *
     * @param int $variantPercent Share of visitors sent to the variant (0-100)
     */
    public static function sampleRatioMismatchPValue(
        int $controlVisitors,
        int $variantVisitors,
        int $variantPercent,
    ): ?float {
        $total = $controlVisitors + $variantVisitors;
        if ($total <= 0 || $variantPercent <= 0 || $variantPercent >= 100) {
            return null;
        }

        $expectedVariant = $total * $variantPercent / 100;
        $expectedControl = $total - $expectedVariant;

        $chiSquared = ($controlVisitors - $expectedControl) ** 2 / $expectedControl
            + ($variantVisitors - $expectedVariant) ** 2 / $expectedVariant;

        return self::chiSquaredPValue($chiSquared);
    }

    /**
     * Normal-approximation confidence interval for a proportion.
     *
     * @return array{lower: float, upper: float, margin: float} As proportions (0-1)
     */
    public static function proportionInterval(float $rate, int $n, float $confidence = 0.95): array
    {
        if ($n <= 0) {
            return ['lower' => 0.0, 'upper' => 0.0, 'margin' => 0.0];
        }

        $z = self::normalQuantile(1 - (1 - $confidence) / 2);
        $margin = $z * sqrt($rate * (1 - $rate) / $n);

        return [
            'lower' => max(0.0, $rate - $margin),
            'upper' => min(1.0, $rate + $margin),
            'margin' => $margin,
        ];
    }
}
