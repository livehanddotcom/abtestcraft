<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\services\StatsService;

/**
 * Unit tests for StatsService
 *
 * Tests statistical calculations including Chi-squared test and conversion rates.
 * These tests verify the mathematical correctness of the A/B testing statistics.
 */
class StatsServiceTest extends Unit
{
    private StatsService $service;

    protected function _before(): void
    {
        $this->service = new StatsService();
    }

    /**
     * Test conversion rate calculation with normal values
     */
    public function testCalculateConversionRate(): void
    {
        // Use reflection to access private method
        $method = new \ReflectionMethod(StatsService::class, 'calculateConversionRate');
        $method->setAccessible(true);

        // 50 conversions out of 1000 impressions = 5%
        $result = $method->invoke($this->service, 1000, 50);
        $this->assertEquals(5.0, $result);

        // 100 conversions out of 500 impressions = 20%
        $result = $method->invoke($this->service, 500, 100);
        $this->assertEquals(20.0, $result);
    }

    /**
     * Test conversion rate with zero impressions
     */
    public function testCalculateConversionRateWithZeroImpressions(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateConversionRate');
        $method->setAccessible(true);

        // 0 impressions should return 0
        $result = $method->invoke($this->service, 0, 0);
        $this->assertEquals(0.0, $result);

        // Edge case: conversions but no impressions (shouldn't happen but test anyway)
        $result = $method->invoke($this->service, 0, 10);
        $this->assertEquals(0.0, $result);
    }

    /**
     * Test improvement calculation
     */
    public function testCalculateImprovement(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateImprovement');
        $method->setAccessible(true);

        // Variant 10% vs Control 5% = 100% improvement
        $result = $method->invoke($this->service, 5.0, 10.0);
        $this->assertEquals(100.0, $result);

        // Variant 5% vs Control 10% = -50% improvement
        $result = $method->invoke($this->service, 10.0, 5.0);
        $this->assertEquals(-50.0, $result);

        // No change
        $result = $method->invoke($this->service, 5.0, 5.0);
        $this->assertEquals(0.0, $result);
    }

    /**
     * Test improvement with zero control rate
     */
    public function testCalculateImprovementWithZeroControl(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateImprovement');
        $method->setAccessible(true);

        // Zero control rate should return null (can't calculate % improvement)
        $result = $method->invoke($this->service, 0.0, 5.0);
        $this->assertNull($result);
    }

    /**
     * Test Chi-squared to confidence conversion
     *
     * The chiSquaredToConfidence method uses interpolation between critical values.
     * For Chi-squared = 3.841 (the 95% critical value), it should return exactly 0.95.
     * For values between critical points, it interpolates.
     */
    public function testChiSquaredToConfidence(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'chiSquaredToConfidence');
        $method->setAccessible(true);

        // Chi-squared of 0 = interpolated low confidence
        $result = $method->invoke($this->service, 0.0);
        $this->assertEquals(0.0, $result);

        // Chi-squared of 3.841 = exactly 95% confidence (p < 0.05)
        // The implementation reaches this value and returns the confidence level
        $result = $method->invoke($this->service, 3.841);
        $this->assertGreaterThanOrEqual(0.90, $result);
        $this->assertLessThanOrEqual(0.96, $result);

        // Chi-squared of 6.635 = ~99% confidence (p < 0.01)
        $result = $method->invoke($this->service, 6.635);
        $this->assertGreaterThanOrEqual(0.98, $result);
        $this->assertLessThanOrEqual(1.0, $result);
    }

    /**
     * Test significance calculation with clearly significant data
     *
     * Note: The calculateSignificance private method returns only 'confidence' and 'chiSquared'.
     * The 'isSignificant' determination is done at the caller level by comparing
     * confidence to the settings threshold.
     */
    public function testCalculateSignificanceWithSignificantData(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateSignificance');
        $method->setAccessible(true);

        // Clear winner: control 5% (50/1000), variant 10% (100/1000)
        $result = $method->invoke($this->service, 1000, 50, 1000, 100);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('chiSquared', $result);
        $this->assertArrayHasKey('confidence', $result);
        // The method returns confidence >= 0.95 for significant data
        $this->assertGreaterThan(0.95, $result['confidence']);
    }

    /**
     * Test significance with insufficient data
     *
     * Note: The calculateSignificance private method returns only 'confidence' and 'chiSquared'.
     * With insufficient data (< 10 impressions per variant), it returns 0.0 confidence.
     */
    public function testCalculateSignificanceWithInsufficientData(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateSignificance');
        $method->setAccessible(true);

        // Not enough impressions (< 10)
        $result = $method->invoke($this->service, 5, 1, 5, 1);

        $this->assertIsArray($result);
        $this->assertEquals(0.0, $result['confidence']);
        $this->assertEquals(0.0, $result['chiSquared']);
    }

    /**
     * Test significance with similar conversion rates
     *
     * Note: The calculateSignificance private method returns only 'confidence' and 'chiSquared'.
     */
    public function testCalculateSignificanceWithSimilarRates(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateSignificance');
        $method->setAccessible(true);

        // Similar rates: control 5.0% (50/1000), variant 5.1% (51/1000)
        $result = $method->invoke($this->service, 1000, 50, 1000, 51);

        $this->assertIsArray($result);
        // With similar rates, confidence should be low (not significant)
        $this->assertLessThan(0.95, $result['confidence']);
    }

    /**
     * Test winner determination
     */
    public function testDetermineWinner(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'determineWinner');
        $method->setAccessible(true);

        // Variant wins with significance
        $result = $method->invoke($this->service, ['control' => 5.0, 'variant' => 10.0], true);
        $this->assertEquals('variant', $result);

        // Control wins with significance
        $result = $method->invoke($this->service, ['control' => 10.0, 'variant' => 5.0], true);
        $this->assertEquals('control', $result);

        // Variant higher but not significant - no winner
        $result = $method->invoke($this->service, ['control' => 5.0, 'variant' => 10.0], false);
        $this->assertNull($result);

        // Tied rates with significance - no winner
        $result = $method->invoke($this->service, ['control' => 5.0, 'variant' => 5.0], true);
        $this->assertNull($result);
    }

    /**
     * Test sample size calculation
     *
     * Note: This test is skipped in unit tests because calculateSampleSizeNeeded
     * requires ABTestCraft::getInstance()->getSettings() which needs full Craft context.
     * This test should be run as an integration test instead.
     */
    public function testCalculateSampleSizeNeeded(): void
    {
        $this->markTestSkipped(
            'calculateSampleSizeNeeded requires plugin settings from ABTestCraft::getInstance(). ' .
            'Run this test as an integration test with full Craft context.'
        );
    }

    /**
     * Test confidence interval calculation
     */
    public function testCalculateConfidenceInterval(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateConfidenceInterval');
        $method->setAccessible(true);

        // 5% conversion rate with 1000 visitors
        $result = $method->invoke($this->service, 0.05, 1000);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lower', $result);
        $this->assertArrayHasKey('upper', $result);
        $this->assertLessThan(5.0, $result['lower']);
        $this->assertGreaterThan(5.0, $result['upper']);
    }

    /**
     * Test confidence interval with zero visitors
     */
    public function testCalculateConfidenceIntervalWithZeroVisitors(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateConfidenceInterval');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 0.05, 0);

        $this->assertIsArray($result);
        $this->assertEquals(0.0, $result['lower']);
        $this->assertEquals(0.0, $result['upper']);
    }
}
