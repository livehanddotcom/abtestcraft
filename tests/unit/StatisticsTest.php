<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\helpers\Statistics;

/**
 * Statistics helper, checked against published reference values
 */
class StatisticsTest extends Unit
{
    /**
     * Chi-squared critical values for 1 degree of freedom (NIST tables)
     */
    public function testChiSquaredPValueMatchesCriticalValues(): void
    {
        $this->assertEqualsWithDelta(0.50, Statistics::chiSquaredPValue(0.455), 0.001);
        $this->assertEqualsWithDelta(0.10, Statistics::chiSquaredPValue(2.706), 0.0005);
        $this->assertEqualsWithDelta(0.05, Statistics::chiSquaredPValue(3.841), 0.0002);
        $this->assertEqualsWithDelta(0.01, Statistics::chiSquaredPValue(6.635), 0.0001);
        $this->assertEqualsWithDelta(0.001, Statistics::chiSquaredPValue(10.828), 0.00002);
        $this->assertSame(1.0, Statistics::chiSquaredPValue(0.0));
    }

    public function testNormalQuantile(): void
    {
        $this->assertEqualsWithDelta(1.959964, Statistics::normalQuantile(0.975), 1e-5);
        $this->assertEqualsWithDelta(0.841621, Statistics::normalQuantile(0.80), 1e-5);
        $this->assertEqualsWithDelta(2.575829, Statistics::normalQuantile(0.995), 1e-5);
        $this->assertEqualsWithDelta(-2.326348, Statistics::normalQuantile(0.01), 1e-5);
        $this->assertEqualsWithDelta(0.0, Statistics::normalQuantile(0.5), 1e-9);
    }

    public function testChiSquared2x2(): void
    {
        // 5% vs 10% of 1,000 visitors each. Expected counts are 75 converted and
        // 925 not in each group: 2 x 25²/75 + 2 x 25²/925 = 18.018
        $chiSquared = Statistics::chiSquared2x2(1000, 50, 1000, 100);
        $this->assertEqualsWithDelta(18.018, $chiSquared, 0.001);
        $this->assertLessThan(0.001, Statistics::chiSquaredPValue($chiSquared));

        // Identical rates: no difference at all
        $this->assertEqualsWithDelta(0.0, Statistics::chiSquared2x2(1000, 50, 1000, 50), 1e-9);

        // Undefined without both conversions and non-conversions
        $this->assertNull(Statistics::chiSquared2x2(100, 0, 100, 0));
        $this->assertNull(Statistics::chiSquared2x2(100, 100, 100, 100));
        $this->assertNull(Statistics::chiSquared2x2(0, 0, 100, 5));
    }

    /**
     * Two-proportion sample sizes, as given by standard calculators
     * (e.g. Evan Miller's, two-sided, 80% power)
     */
    public function testSampleSizePerGroup(): void
    {
        // 5% baseline, +10% relative (5% -> 5.5%), 95% confidence: ~31,200 per group
        $this->assertEqualsWithDelta(31234, Statistics::sampleSizePerGroup(0.05, 0.10, 0.95), 150);
        // 10% baseline, +20% relative (10% -> 12%): ~3,840 per group
        $this->assertEqualsWithDelta(3841, Statistics::sampleSizePerGroup(0.10, 0.20, 0.95), 50);
        // A lower confidence level needs fewer visitors
        $this->assertLessThan(
            Statistics::sampleSizePerGroup(0.05, 0.10, 0.95),
            Statistics::sampleSizePerGroup(0.05, 0.10, 0.90)
        );
        // A lower baseline needs more
        $this->assertGreaterThan(
            Statistics::sampleSizePerGroup(0.10, 0.10, 0.95),
            Statistics::sampleSizePerGroup(0.02, 0.10, 0.95)
        );
    }

    public function testSampleRatioMismatch(): void
    {
        // Exactly as configured
        $this->assertEqualsWithDelta(1.0, Statistics::sampleRatioMismatchPValue(500, 500, 50), 1e-9);
        // 560/440 of 1,000 on a 50/50 split is very unlikely by chance
        $this->assertLessThan(0.001, Statistics::sampleRatioMismatchPValue(560, 440, 50));
        // A 30/70 split checked against its own configuration
        $this->assertGreaterThan(0.5, Statistics::sampleRatioMismatchPValue(298, 702, 70));
        // Can't be tested without visitors or with everyone in one group
        $this->assertNull(Statistics::sampleRatioMismatchPValue(0, 0, 50));
        $this->assertNull(Statistics::sampleRatioMismatchPValue(100, 0, 0));
        $this->assertNull(Statistics::sampleRatioMismatchPValue(0, 100, 100));
    }

    public function testProportionInterval(): void
    {
        $interval = Statistics::proportionInterval(0.05, 1000);
        $this->assertEqualsWithDelta(0.0135, $interval['margin'], 0.0001);
        $this->assertEqualsWithDelta(0.0365, $interval['lower'], 0.0001);
        $this->assertEqualsWithDelta(0.0635, $interval['upper'], 0.0001);

        $this->assertSame(['lower' => 0.0, 'upper' => 0.0, 'margin' => 0.0], Statistics::proportionInterval(0.05, 0));
        $this->assertSame(0.0, Statistics::proportionInterval(0.0, 10)['lower']);
    }
}
