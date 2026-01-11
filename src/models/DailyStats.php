<?php

declare(strict_types=1);

namespace livehand\abtestcraft\models;

use craft\base\Model;
use DateTime;

/**
 * Daily stats model
 */
class DailyStats extends Model
{
    public ?int $id = null;
    public ?int $testId = null;
    public ?string $date = null;
    public string $variant = '';
    public int $impressions = 0;
    public int $conversions = 0;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['testId', 'date', 'variant'], 'required'],
            [['variant'], 'in', 'range' => [Test::VARIANT_CONTROL, Test::VARIANT_VARIANT]],
            [['impressions', 'conversions'], 'integer', 'min' => 0],
        ];
    }

    /**
     * Get conversion rate
     */
    public function getConversionRate(): float
    {
        if ($this->impressions === 0) {
            return 0.0;
        }
        return $this->conversions / $this->impressions;
    }

    /**
     * Get conversion rate as percentage
     */
    public function getConversionRatePercent(): float
    {
        return $this->getConversionRate() * 100;
    }
}
