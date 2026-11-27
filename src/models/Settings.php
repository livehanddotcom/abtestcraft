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

    /** @var bool Known bots and crawlers see the control and aren't counted */
    public bool $excludeBots = true;

    /**
     * @var bool People logged in with control panel access see the control and
     * aren't counted (they can preview a variant with ?abtestcraft=variant)
     */
    public bool $excludeControlPanelUsers = true;

    /**
     * @var int Days a test must run before a winner can be called, so weekday
     * and weekend visitors are both represented
     */
    public int $minimumTestDays = 7;

    /** @var bool Send a weekly summary of running tests (off until switched on) */
    public bool $sendWeeklySummary = false;

    /**
     * @var bool Only include visitors who've given consent. Without consent
     * they see the control, get no cookies and aren't counted.
     */
    public bool $requireConsent = false;

    /**
     * @var string Google Consent Mode signal that means consent, or '' not to
     * use Consent Mode
     */
    public string $consentModeSignal = 'analytics_storage';

    /**
     * @var string Consent banner cookie to check: 'auto' (any known banner),
     * a preset (cookiebot, cookieyes, complianz, onetrust), 'custom' or 'none'
     */
    public string $consentCookie = 'auto';

    /** @var string OneTrust group ID for performance/analytics cookies (C0002 by default; varies per account) */
    public string $consentOneTrustGroup = 'C0002';

    /** @var string Custom consent cookie name */
    public string $consentCookieName = '';

    /** @var string Text the custom consent cookie's value must contain (empty: the cookie existing is enough) */
    public string $consentCookieValue = '';

    public const CONSENT_SIGNALS = ['analytics_storage', 'personalization_storage', 'functionality_storage', ''];
    public const CONSENT_COOKIES = [
        'auto',
        'cookiebot',
        'cookieyes',
        'complianz',
        'onetrust',
        'cookieconsent',
        'custom',
        'none',
    ];

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
            [['minimumTestDays'], 'integer', 'min' => 0, 'max' => 90],
            [
                [
                    'trackPhoneClicks',
                    'trackEmailClicks',
                    'trackFormSubmissions',
                    'trackFileDownloads',
                    'sendSignificanceEmail',
                    'enableDataLayer',
                    'excludeBots',
                    'excludeControlPanelUsers',
                    'sendWeeklySummary',
                    'requireConsent',
                ],
                'boolean',
            ],
            [['consentModeSignal'], 'in', 'range' => self::CONSENT_SIGNALS],
            [['consentCookie'], 'in', 'range' => self::CONSENT_COOKIES],
            [['consentCookieName', 'consentCookieValue'], 'string', 'max' => 255],
            [
                ['consentOneTrustGroup'],
                'match',
                'pattern' => '/^[A-Za-z0-9_]{1,20}$/',
                'message' => 'Use the group ID exactly as OneTrust shows it, such as C0002.',
            ],
            [
                ['consentCookieName'],
                'match',
                'pattern' => '/^[A-Za-z0-9_.\-]*$/',
                'message' => 'Use the cookie\'s exact name.',
            ],
            [
                ['consentCookieName'],
                'required',
                'when' => fn(self $model) => $model->requireConsent && $model->consentCookie === 'custom',
            ],
            [['notificationEmails'], 'string'],
            [['notificationEmails'], 'validateEmailList'],
            [
                ['conversionCountingMode'],
                'in',
                'range' => [self::COUNTING_FIRST_ONLY, self::COUNTING_PER_GOAL_TYPE, self::COUNTING_UNLIMITED],
            ],
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
