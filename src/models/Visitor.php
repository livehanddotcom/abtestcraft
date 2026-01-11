<?php

declare(strict_types=1);

namespace livehand\abtestcraft\models;

use craft\base\Model;
use DateTime;

/**
 * Visitor model
 */
class Visitor extends Model
{
    public ?int $id = null;
    public ?int $testId = null;
    public string $visitorId = '';
    public string $variant = '';
    public bool $converted = false;
    public ?string $conversionType = null;
    public ?DateTime $dateConverted = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['testId', 'visitorId', 'variant'], 'required'],
            [['visitorId'], 'string', 'length' => 36],
            [['variant'], 'in', 'range' => [Test::VARIANT_CONTROL, Test::VARIANT_VARIANT]],
            [['converted'], 'boolean'],
            [['conversionType'], 'in', 'range' => ['phone', 'form', 'page', 'email', 'download', null]],
        ];
    }

    /**
     * Check if visitor is in control group
     */
    public function isControl(): bool
    {
        return $this->variant === Test::VARIANT_CONTROL;
    }

    /**
     * Check if visitor is in variant group
     */
    public function isVariant(): bool
    {
        return $this->variant === Test::VARIANT_VARIANT;
    }

    /**
     * Check if visitor has converted
     */
    public function hasConverted(): bool
    {
        return $this->converted;
    }
}
