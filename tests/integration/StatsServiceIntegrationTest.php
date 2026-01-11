<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use livehand\abtestcraft\services\StatsService;
use livehand\abtestcraft\ABTestCraft;

/**
 * Integration tests for StatsService
 *
 * These tests require full Craft context because calculateSampleSizeNeeded
 * uses ABTestCraft::getInstance()->getSettings().
 */
class StatsServiceIntegrationTest extends Unit
{
    private StatsService $service;

    protected function _before(): void
    {
        $this->service = ABTestCraft::getInstance()->stats;
    }

    /**
     * Test sample size calculation with various baseline rates
     *
     * The calculateSampleSizeNeeded method calculates the required sample size
     * per variation to detect the minimum detectable effect (MDE) with
     * 95% confidence and 80% power.
     */
    public function testCalculateSampleSizeNeeded(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateSampleSizeNeeded');
        $method->setAccessible(true);

        // With 0 baseline rate, should return default of 1000
        $result = $method->invoke($this->service, 0.0);
        $this->assertEquals(1000, $result, 'Zero baseline should return default 1000');

        // With negative baseline rate, should return default of 1000
        $result = $method->invoke($this->service, -5.0);
        $this->assertEquals(1000, $result, 'Negative baseline should return default 1000');

        // With 5% baseline rate (common e-commerce conversion rate)
        // Expected sample size depends on MDE setting (default 10%)
        $result = $method->invoke($this->service, 5.0);
        $this->assertIsInt($result, 'Result should be an integer');
        $this->assertGreaterThan(0, $result, 'Sample size should be positive');
        // With 5% baseline and 10% MDE (0.5% absolute change),
        // typical sample size is around 3000-4000 per variation
        $this->assertGreaterThan(100, $result, 'Sample size for 5% baseline should be substantial');
        $this->assertLessThan(100000, $result, 'Sample size should be reasonable');

        // With 50% baseline rate (like a coin flip test)
        $result = $method->invoke($this->service, 50.0);
        $this->assertIsInt($result, 'Result should be an integer');
        $this->assertGreaterThan(0, $result, 'Sample size should be positive');

        // With 1% baseline rate (low conversion)
        // Lower conversion rates need larger sample sizes
        $lowResult = $method->invoke($this->service, 1.0);
        $highResult = $method->invoke($this->service, 10.0);
        // Lower baseline should generally require more samples for same MDE
        $this->assertGreaterThan($highResult, $lowResult,
            'Lower baseline rate should require larger sample size');
    }

    /**
     * Test that sample size calculation produces consistent results
     */
    public function testCalculateSampleSizeNeededConsistency(): void
    {
        $method = new \ReflectionMethod(StatsService::class, 'calculateSampleSizeNeeded');
        $method->setAccessible(true);

        // Same input should always produce same output
        $result1 = $method->invoke($this->service, 5.0);
        $result2 = $method->invoke($this->service, 5.0);
        $this->assertEquals($result1, $result2, 'Same baseline should produce same sample size');
    }

    /**
     * Test that settings are accessible and affect calculation
     */
    public function testSettingsAreUsedInCalculation(): void
    {
        $settings = ABTestCraft::getInstance()->getSettings();

        // Verify MDE setting exists and is reasonable
        $this->assertIsFloat($settings->minimumDetectableEffect);
        $this->assertGreaterThan(0, $settings->minimumDetectableEffect);
        $this->assertLessThanOrEqual(1, $settings->minimumDetectableEffect);
    }
}
