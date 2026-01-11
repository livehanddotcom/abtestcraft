<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\services\AssignmentService;
use livehand\abtestcraft\models\Test;

/**
 * Unit tests for AssignmentService
 *
 * Tests variant assignment logic and cookie validation.
 */
class AssignmentServiceTest extends Unit
{
    private AssignmentService $service;

    protected function _before(): void
    {
        $this->service = new AssignmentService();
    }

    /**
     * Test determineVariant returns valid variants based on traffic split
     */
    public function testDetermineVariantReturnsValidVariant(): void
    {
        $method = new \ReflectionMethod(AssignmentService::class, 'determineVariant');
        $method->setAccessible(true);

        // Run many times to ensure both variants are possible
        $results = [];
        for ($i = 0; $i < 100; $i++) {
            $result = $method->invoke($this->service, 50);
            $results[$result] = true;
        }

        // Both variants should be possible with 50/50 split
        $this->assertArrayHasKey(Test::VARIANT_CONTROL, $results);
        $this->assertArrayHasKey(Test::VARIANT_VARIANT, $results);
    }

    /**
     * Test determineVariant with 0% traffic split always returns control
     */
    public function testDetermineVariantWithZeroSplit(): void
    {
        $method = new \ReflectionMethod(AssignmentService::class, 'determineVariant');
        $method->setAccessible(true);

        for ($i = 0; $i < 10; $i++) {
            $result = $method->invoke($this->service, 0);
            $this->assertEquals(Test::VARIANT_CONTROL, $result);
        }
    }

    /**
     * Test determineVariant with 100% traffic split always returns variant
     */
    public function testDetermineVariantWithFullSplit(): void
    {
        $method = new \ReflectionMethod(AssignmentService::class, 'determineVariant');
        $method->setAccessible(true);

        for ($i = 0; $i < 10; $i++) {
            $result = $method->invoke($this->service, 100);
            $this->assertEquals(Test::VARIANT_VARIANT, $result);
        }
    }

    /**
     * Test cookie value validation for visitor ID
     *
     * Note: This test is skipped in unit tests because validateCookieValue
     * uses Craft::warning() which requires full Craft context.
     * This test should be run as an integration test instead.
     */
    public function testValidateCookieValueForVisitorId(): void
    {
        $this->markTestSkipped(
            'validateCookieValue uses Craft::warning() which requires full Craft context. ' .
            'Run this test as an integration test.'
        );
    }

    /**
     * Test cookie value validation for variant cookies
     *
     * Note: This test is skipped in unit tests because validateCookieValue
     * uses Craft::warning() which requires full Craft context.
     * This test should be run as an integration test instead.
     */
    public function testValidateCookieValueForVariant(): void
    {
        $this->markTestSkipped(
            'validateCookieValue uses Craft::warning() which requires full Craft context. ' .
            'Run this test as an integration test.'
        );
    }

    /**
     * Test that traffic split distribution is roughly correct
     *
     * With 50% split, we expect roughly equal distribution over many trials.
     * Using chi-squared test to verify statistical correctness.
     */
    public function testTrafficSplitDistribution(): void
    {
        $method = new \ReflectionMethod(AssignmentService::class, 'determineVariant');
        $method->setAccessible(true);

        $trials = 1000;
        $split = 50;
        $variantCount = 0;

        for ($i = 0; $i < $trials; $i++) {
            if ($method->invoke($this->service, $split) === Test::VARIANT_VARIANT) {
                $variantCount++;
            }
        }

        $controlCount = $trials - $variantCount;
        $expectedEach = $trials / 2;

        // Chi-squared test: should be less than 3.84 for 95% confidence
        $chiSquared = (pow($variantCount - $expectedEach, 2) + pow($controlCount - $expectedEach, 2)) / $expectedEach;

        $this->assertLessThan(10.83, $chiSquared, "Distribution should be statistically reasonable (chi-squared < 10.83 for 99.9% CI)");

        // Also check rough percentage (within 10%)
        $actualPercentage = ($variantCount / $trials) * 100;
        $this->assertGreaterThan(40, $actualPercentage);
        $this->assertLessThan(60, $actualPercentage);
    }

    /**
     * Test traffic split with 70/30 distribution
     */
    public function testTrafficSplit70Distribution(): void
    {
        $method = new \ReflectionMethod(AssignmentService::class, 'determineVariant');
        $method->setAccessible(true);

        $trials = 1000;
        $split = 70; // 70% to variant
        $variantCount = 0;

        for ($i = 0; $i < $trials; $i++) {
            if ($method->invoke($this->service, $split) === Test::VARIANT_VARIANT) {
                $variantCount++;
            }
        }

        // Should be roughly 70% variant
        $actualPercentage = ($variantCount / $trials) * 100;
        $this->assertGreaterThan(60, $actualPercentage, "Variant percentage should be > 60% for 70% split");
        $this->assertLessThan(80, $actualPercentage, "Variant percentage should be < 80% for 70% split");
    }
}
