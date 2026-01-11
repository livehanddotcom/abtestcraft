<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\models\Settings;

/**
 * Unit tests for Settings model
 *
 * Tests settings validation including email validation.
 */
class SettingsTest extends Unit
{
    /**
     * Test default values
     */
    public function testDefaultValues(): void
    {
        $settings = new Settings();

        $this->assertEquals(30, $settings->cookieDuration);
        $this->assertTrue($settings->trackPhoneClicks);
        $this->assertTrue($settings->trackEmailClicks);
        $this->assertTrue($settings->trackFormSubmissions);
        $this->assertTrue($settings->trackFileDownloads);
        $this->assertTrue($settings->sendSignificanceEmail);
        $this->assertEquals('', $settings->notificationEmails);
        $this->assertEquals(0.95, $settings->significanceThreshold);
        $this->assertEquals(0.10, $settings->minimumDetectableEffect);
        $this->assertFalse($settings->enableDataLayer);
        $this->assertEquals(10, $settings->conversionRateLimit);
        $this->assertEquals(Settings::COUNTING_PER_GOAL_TYPE, $settings->conversionCountingMode);
    }

    /**
     * Test valid email list
     */
    public function testValidEmailList(): void
    {
        $settings = new Settings();

        // Single email
        $settings->notificationEmails = 'test@example.com';
        $this->assertTrue($settings->validate(['notificationEmails']));

        // Multiple emails
        $settings->notificationEmails = 'test@example.com, user@domain.org';
        $this->assertTrue($settings->validate(['notificationEmails']));

        // Empty is valid
        $settings->notificationEmails = '';
        $this->assertTrue($settings->validate(['notificationEmails']));
    }

    /**
     * Test invalid email list
     */
    public function testInvalidEmailList(): void
    {
        $settings = new Settings();

        // Invalid email
        $settings->notificationEmails = 'not-an-email';
        $this->assertFalse($settings->validate(['notificationEmails']));

        // Mix of valid and invalid
        $settings->notificationEmails = 'valid@example.com, invalid';
        $this->assertFalse($settings->validate(['notificationEmails']));
    }

    /**
     * Test getNotificationEmailsArray with valid emails
     */
    public function testGetNotificationEmailsArrayValid(): void
    {
        $settings = new Settings();

        $settings->notificationEmails = 'one@example.com, two@example.com, three@example.com';
        $emails = $settings->getNotificationEmailsArray();

        $this->assertCount(3, $emails);
        $this->assertContains('one@example.com', $emails);
        $this->assertContains('two@example.com', $emails);
        $this->assertContains('three@example.com', $emails);
    }

    /**
     * Test getNotificationEmailsArray filters invalid emails
     */
    public function testGetNotificationEmailsArrayFiltersInvalid(): void
    {
        $settings = new Settings();

        $settings->notificationEmails = 'valid@example.com, invalid, another@domain.org';
        $emails = $settings->getNotificationEmailsArray();

        $this->assertCount(2, $emails);
        $this->assertContains('valid@example.com', $emails);
        $this->assertContains('another@domain.org', $emails);
        $this->assertNotContains('invalid', $emails);
    }

    /**
     * Test getNotificationEmailsArray with empty string
     */
    public function testGetNotificationEmailsArrayEmpty(): void
    {
        $settings = new Settings();

        $settings->notificationEmails = '';
        $emails = $settings->getNotificationEmailsArray();

        $this->assertIsArray($emails);
        $this->assertEmpty($emails);
    }

    /**
     * Test cookie duration validation
     */
    public function testCookieDurationValidation(): void
    {
        $settings = new Settings();

        // Valid values
        $settings->cookieDuration = 1;
        $this->assertTrue($settings->validate(['cookieDuration']));

        $settings->cookieDuration = 365;
        $this->assertTrue($settings->validate(['cookieDuration']));

        // Invalid values
        $settings->cookieDuration = 0;
        $this->assertFalse($settings->validate(['cookieDuration']));

        $settings->cookieDuration = 366;
        $this->assertFalse($settings->validate(['cookieDuration']));
    }

    /**
     * Test significance threshold validation
     */
    public function testSignificanceThresholdValidation(): void
    {
        $settings = new Settings();

        // Valid values
        $settings->significanceThreshold = 0.8;
        $this->assertTrue($settings->validate(['significanceThreshold']));

        $settings->significanceThreshold = 0.95;
        $this->assertTrue($settings->validate(['significanceThreshold']));

        $settings->significanceThreshold = 0.99;
        $this->assertTrue($settings->validate(['significanceThreshold']));

        // Invalid values
        $settings->significanceThreshold = 0.79;
        $this->assertFalse($settings->validate(['significanceThreshold']));

        $settings->significanceThreshold = 1.0;
        $this->assertFalse($settings->validate(['significanceThreshold']));
    }

    /**
     * Test conversion counting mode validation
     */
    public function testConversionCountingModeValidation(): void
    {
        $settings = new Settings();

        // Valid values
        $settings->conversionCountingMode = Settings::COUNTING_FIRST_ONLY;
        $this->assertTrue($settings->validate(['conversionCountingMode']));

        $settings->conversionCountingMode = Settings::COUNTING_PER_GOAL_TYPE;
        $this->assertTrue($settings->validate(['conversionCountingMode']));

        $settings->conversionCountingMode = Settings::COUNTING_UNLIMITED;
        $this->assertTrue($settings->validate(['conversionCountingMode']));

        // Invalid value
        $settings->conversionCountingMode = 'invalid_mode';
        $this->assertFalse($settings->validate(['conversionCountingMode']));
    }

    /**
     * Test rate limit validation
     */
    public function testRateLimitValidation(): void
    {
        $settings = new Settings();

        // Valid values
        $settings->conversionRateLimit = 1;
        $this->assertTrue($settings->validate(['conversionRateLimit']));

        $settings->conversionRateLimit = 50;
        $this->assertTrue($settings->validate(['conversionRateLimit']));

        $settings->conversionRateLimit = 100;
        $this->assertTrue($settings->validate(['conversionRateLimit']));

        // Invalid values
        $settings->conversionRateLimit = 0;
        $this->assertFalse($settings->validate(['conversionRateLimit']));

        $settings->conversionRateLimit = 101;
        $this->assertFalse($settings->validate(['conversionRateLimit']));
    }
}
