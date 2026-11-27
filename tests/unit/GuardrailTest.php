<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\helpers\Statistics;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\services\StatsService;

/**
 * The safety guardrail must not raise false alarms, even when it's checked
 * continuously, and should still catch real large drops.
 *
 * These run the real decision code (StatsService::evaluateGuardrail) on
 * simulated tests, with a fixed random seed so results are repeatable.
 */
class GuardrailTest extends Unit
{
    private StatsService $service;
    private \ReflectionMethod $evaluate;

    protected function _before(): void
    {
        $this->service = new StatsService();
        $this->evaluate = new \ReflectionMethod(StatsService::class, 'evaluateGuardrail');
    }

    public function testMixtureSprtRatio(): void
    {
        // No difference: no evidence
        $this->assertLessThan(1, Statistics::mixtureSprtRatio(2000, 200, 2000, 200, 0.025));
        // 10% vs 6% of 2,000 each: overwhelming evidence
        $this->assertGreaterThan(1000, Statistics::mixtureSprtRatio(2000, 200, 2000, 120, 0.025));
        // Undefined without variance
        $this->assertNull(Statistics::mixtureSprtRatio(100, 0, 100, 0, 0.025));
        $this->assertNull(Statistics::mixtureSprtRatio(0, 0, 100, 5, 0.025));
    }

    public function testSampleSizeForDrop(): void
    {
        // Fixed-sample estimates for a 25% drop at 99% one-sided confidence
        $this->assertEqualsWithDelta(2560, Statistics::sampleSizeForDrop(0.10, 0.25, 0.99), 100);
        $this->assertEqualsWithDelta(5370, Statistics::sampleSizeForDrop(0.05, 0.25, 0.99), 200);
        $this->assertNull(Statistics::sampleSizeForDrop(0.0, 0.25, 0.99));
    }

    public function testWaitsForMinimumData(): void
    {
        // A 60% drop, but only 200 visitors per version
        $result = $this->evaluate(200, 30, 200, 12);
        $this->assertSame('waiting', $result['status']);
        $this->assertFalse($result['shouldTrigger']);
    }

    public function testIgnoresDropsBelowTheThreshold(): void
    {
        // 15% drop on 20,000 visitors each: real and significant, but under 25%
        $result = $this->evaluate(20000, 2000, 20000, 1700);
        $this->assertSame('watching', $result['status']);
        $this->assertFalse($result['shouldTrigger']);
    }

    public function testTriggersOnALargeClearDrop(): void
    {
        $result = $this->evaluate(2000, 200, 2000, 120);
        $this->assertTrue($result['shouldTrigger']);
        $this->assertSame(40.0, $result['observedDrop']);
    }

    public function testOffUnlessEnabled(): void
    {
        $result = $this->evaluate(2000, 200, 2000, 120, ['enabled' => false]);
        $this->assertSame('off', $result['status']);
        $this->assertFalse($result['shouldTrigger']);
    }

    /**
     * 400 simulated tests where both versions convert at exactly 10%, checked
     * every 100 visitors up to 6,000 per version (60 checks each), with the
     * default settings. A naive "25% behind" rule fires constantly here; the
     * guardrail should almost never fire (its bound is 1%).
     */
    public function testAlmostNoFalseAlarmsWhenCheckedContinuously(): void
    {
        mt_srand(20261126);
        $alarms = $this->simulate(400, 0.10, 0.10);

        $this->assertLessThanOrEqual(4, $alarms, "{$alarms} of 400 identical tests raised a false alarm");
    }

    /**
     * The same, at the least strict setting (95%) and a lower 20% threshold
     */
    public function testFewFalseAlarmsAtTheLeastStrictSetting(): void
    {
        mt_srand(11262026);
        $alarms = $this->simulate(400, 0.05, 0.05, ['confidence' => 0.95, 'drop' => 20]);

        $this->assertLessThanOrEqual(20, $alarms, "{$alarms} of 400 identical tests raised a false alarm");
    }

    /**
     * A real 40% drop (10% to 6%) is caught in nearly every test by 6,000
     * visitors per version
     */
    public function testCatchesARealLargeDrop(): void
    {
        mt_srand(42);
        $caught = $this->simulate(200, 0.10, 0.06);

        $this->assertGreaterThanOrEqual(190, $caught, "Only {$caught} of 200 real 40% drops were caught");
    }

    /**
     * @return int How many simulated tests triggered the guardrail
     */
    private function simulate(int $runs, float $controlRate, float $variantRate, array $settings = []): int
    {
        $triggered = 0;
        for ($run = 0; $run < $runs; $run++) {
            $converted = ['control' => 0, 'variant' => 0];
            for ($visitors = 1; $visitors <= 6000; $visitors++) {
                $converted['control'] += mt_rand() / mt_getrandmax() < $controlRate ? 1 : 0;
                $converted['variant'] += mt_rand() / mt_getrandmax() < $variantRate ? 1 : 0;

                if ($visitors % 100 === 0
                    && $this->evaluate($visitors, $converted['control'], $visitors, $converted['variant'], $settings)['shouldTrigger']
                ) {
                    $triggered++;
                    break;
                }
            }
        }

        return $triggered;
    }

    private function evaluate(int $controlVisitors, int $controlConverted, int $variantVisitors, int $variantConverted, array $settings = []): array
    {
        $test = new Test();
        $test->guardrail = array_merge(['enabled' => true], $settings);

        $totals = [
            Test::VARIANT_CONTROL => ['*' => ['impressions' => 0, 'conversions' => 0, 'visitors' => $controlVisitors, 'convertedVisitors' => $controlConverted]],
            Test::VARIANT_VARIANT => ['*' => ['impressions' => 0, 'conversions' => 0, 'visitors' => $variantVisitors, 'convertedVisitors' => $variantConverted]],
        ];

        return $this->evaluate->invoke($this->service, $test, $totals, [
            Test::VARIANT_CONTROL => $controlVisitors,
            Test::VARIANT_VARIANT => $variantVisitors,
        ]);
    }
}
