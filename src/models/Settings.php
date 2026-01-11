<?php

declare(strict_types=1);

namespace livehand\abtestcraft\models;

use craft\base\Model;

/**
 * Split Test plugin settings
 */
class Settings extends Model
{
    /** @var int Cookie duration in days */
    public int $cookieDuration = 30;

    /** @var bool Track phone link clicks */
    public bool $trackPhoneClicks = true;

    /** @var bool Track email link clicks */
    public bool $trackEmailClicks = true;

    /** @var bool Track form submissions */
    public bool $trackFormSubmissions = true;

    /** @var bool Track file downloads */
    public bool $trackFileDownloads = true;

    /** @var bool Send email when test reaches significance */
    public bool $sendSignificanceEmail = true;

    /** @var string Comma-separated email addresses for notifications */
    public string $notificationEmails = '';

    /** @var float Significance threshold (0.95 = 95% confidence) */
    public float $significanceThreshold = 0.95;

    /** @var float Minimum detectable effect (0.10 = 10% relative improvement) */
    public float $minimumDetectableEffect = 0.10;

    /** @var bool Enable GA4 dataLayer integration */
    public bool $enableDataLayer = false;

    /** @var int Maximum conversions per minute per IP per test */
    public int $conversionRateLimit = 10;

    /**
     * Conversion counting mode:
     * - 'first_only': Count only the first conversion (any goal type) per visitor
     * - 'per_goal_type': Count one conversion per goal type per visitor (default, recommended)
     * - 'unlimited': Count all conversions
     */
    public string $conversionCountingMode = 'per_goal_type';

    // Conversion counting mode constants
    public const COUNTING_FIRST_ONLY = 'first_only';
    public const COUNTING_PER_GOAL_TYPE = 'per_goal_type';
    public const COUNTING_UNLIMITED = 'unlimited';

    public function defineRules(): array
    {
        return [
            [['cookieDuration'], 'integer', 'min' => 1, 'max' => 365],
            [['significanceThreshold'], 'number', 'min' => 0.8, 'max' => 0.99],
            [['minimumDetectableEffect'], 'number', 'min' => 0.05, 'max' => 0.30],
            [['conversionRateLimit'], 'integer', 'min' => 1, 'max' => 100],
            [['trackPhoneClicks', 'trackEmailClicks', 'trackFormSubmissions', 'trackFileDownloads', 'sendSignificanceEmail', 'enableDataLayer'], 'boolean'],
            [['notificationEmails'], 'string'],
            [['notificationEmails'], 'validateEmailList'],
            [['conversionCountingMode'], 'in', 'range' => [self::COUNTING_FIRST_ONLY, self::COUNTING_PER_GOAL_TYPE, self::COUNTING_UNLIMITED]],
        ];
    }

    /**
     * Validates that notificationEmails contains valid email addresses
     *
     * @param string $attribute The attribute being validated
     */
    public function validateEmailList(string $attribute): void
    {
        $value = $this->$attribute;

        // Empty is valid
        if (empty($value)) {
            return;
        }

        // Split by comma and validate each email
        $emails = array_map('trim', explode(',', $value));

        foreach ($emails as $email) {
            if (empty($email)) {
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, "Invalid email address: {$email}");
                return;
            }
        }
    }

    /**
     * Get notification emails as an array
     *
     * @return array<string>
     */
    public function getNotificationEmailsArray(): array
    {
        if (empty($this->notificationEmails)) {
            return [];
        }

        return array_filter(
            array_map('trim', explode(',', $this->notificationEmails)),
            fn($email) => !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
        );
    }
}
