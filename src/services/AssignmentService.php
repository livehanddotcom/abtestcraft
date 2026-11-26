<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\models\Visitor;
use livehand\abtestcraft\records\VisitorRecord;
use livehand\abtestcraft\ABTestCraft;

/**
 * Assignment service - handles visitor variant assignment
 */
class AssignmentService extends Component
{
    private const VISITOR_COOKIE_PREFIX = '_abtestcraft_';
    private const VISITOR_ID_COOKIE = '_abtestcraft_vid';

    /**
     * "In a test" marker for the page-visit beacon (see PageVisitService).
     * Readable by JavaScript and holds no data, so the beacon only sends a
     * request for visitors who've been assigned to a test.
     */
    public const MARKER_COOKIE = '_abtestcraft_on';

    /**
     * Get or assign a variant for a visitor
     */
    public function getOrAssignVariant(Test $test): string
    {
        // Check if visitor already has an assignment for this test
        $variant = $this->getVisitorVariant($test) ?? $this->assignVariant($test);

        // Also covers visitors assigned before the marker existed
        $this->ensureMarkerCookie();

        return $variant;
    }

    /**
     * Get the current visitor's variant for a test (if already assigned)
     */
    public function getVisitorVariant(Test $test): ?string
    {
        $cookieName = $this->getTestCookieName($test);
        $variant = $this->getCookieValue($cookieName);

        if ($variant && in_array($variant, [Test::VARIANT_CONTROL, Test::VARIANT_VARIANT], true)) {
            return $variant;
        }

        return null;
    }

    /**
     * Assign a variant to the current visitor
     */
    public function assignVariant(Test $test): string
    {
        $visitorId = $this->getOrCreateVisitorId();

        // Check database for existing assignment
        $existingRecord = VisitorRecord::findOne([
            'testId' => $test->id,
            'visitorId' => $visitorId,
        ]);

        if ($existingRecord) {
            // Set cookie and return
            $this->setVariantCookie($test, $existingRecord->variant);
            return $existingRecord->variant;
        }

        // Determine variant based on traffic split
        $variant = $this->determineVariant($test->trafficSplit);

        // Save to database
        $visitor = new Visitor();
        $visitor->testId = $test->id;
        $visitor->visitorId = $visitorId;
        $visitor->variant = $variant;

        $record = new VisitorRecord();
        $record->testId = $visitor->testId;
        $record->visitorId = $visitor->visitorId;
        $record->variant = $visitor->variant;
        $record->converted = false;

        if (!$record->save()) {
            $errorMsg = "Failed to save visitor assignment for test {$test->id}: " .
                json_encode($record->getErrors());
            Craft::error($errorMsg, __METHOD__);
            // Still set cookie and return variant - the user should see the test
            // even if we couldn't persist their assignment (they'll get re-assigned next visit)
            Craft::warning("Visitor assignment not persisted - will be reassigned on next visit", __METHOD__);
        }

        // Set cookie
        $this->setVariantCookie($test, $variant);

        return $variant;
    }

    /**
     * Get or create a visitor ID
     */
    public function getOrCreateVisitorId(): string
    {
        $visitorId = $this->getCookieValue(self::VISITOR_ID_COOKIE);

        if (!$visitorId) {
            $visitorId = StringHelper::UUID();
            $this->setVisitorIdCookie($visitorId);
        }

        return $visitorId;
    }

    /**
     * The current visitor's ID from their cookie, without creating one.
     * Null for visitors who were never assigned to a test.
     */
    public function getVisitorId(): ?string
    {
        return $this->getCookieValue(self::VISITOR_ID_COOKIE);
    }

    /**
     * Get visitor record for a test
     */
    public function getVisitorRecord(Test $test): ?VisitorRecord
    {
        $visitorId = $this->getVisitorId();

        if (!$visitorId) {
            return null;
        }

        return VisitorRecord::findOne([
            'testId' => $test->id,
            'visitorId' => $visitorId,
        ]);
    }

    /**
     * Determine which variant to assign based on traffic split
     */
    private function determineVariant(int $trafficSplit): string
    {
        // trafficSplit is percentage to variant (0-100)
        $random = random_int(1, 100);

        return $random <= $trafficSplit ? Test::VARIANT_VARIANT : Test::VARIANT_CONTROL;
    }

    /**
     * Get the cookie name for a test
     */
    private function getTestCookieName(Test $test): string
    {
        // Include site ID to handle multi-site setups
        return self::VISITOR_COOKIE_PREFIX . $test->siteId . '_' . $test->handle;
    }

    /**
     * Set the variant cookie
     */
    private function setVariantCookie(Test $test, string $variant): void
    {
        $cookieName = $this->getTestCookieName($test);
        $duration = $this->getCookieDuration();
        $expires = time() + ($duration * 24 * 60 * 60);

        setcookie($cookieName, $variant, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Craft::$app->getRequest()->getIsSecureConnection(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Also set in $_COOKIE for immediate availability
        $_COOKIE[$cookieName] = $variant;
    }

    /**
     * Set the visitor ID cookie
     */
    private function setVisitorIdCookie(string $visitorId): void
    {
        $duration = $this->getCookieDuration();
        $expires = time() + ($duration * 24 * 60 * 60);

        setcookie(self::VISITOR_ID_COOKIE, $visitorId, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Craft::$app->getRequest()->getIsSecureConnection(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Also set in $_COOKIE for immediate availability
        $_COOKIE[self::VISITOR_ID_COOKIE] = $visitorId;
    }

    /**
     * Set the "in a test" marker cookie if the visitor doesn't have it.
     * Not HttpOnly on purpose: the cache-safe page-visit beacon reads it to
     * decide whether to send anything. Its value is only "1".
     */
    private function ensureMarkerCookie(): void
    {
        if ($this->getCookieValue(self::MARKER_COOKIE) === '1') {
            return;
        }

        setcookie(self::MARKER_COOKIE, '1', [
            'expires' => time() + ($this->getCookieDuration() * 24 * 60 * 60),
            'path' => '/',
            'secure' => Craft::$app->getRequest()->getIsSecureConnection(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::MARKER_COOKIE] = '1';
    }

    /**
     * Get cookie duration from settings
     */
    private function getCookieDuration(): int
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        return $settings->cookieDuration ?? 30;
    }

    /**
     * Get a cookie value using Craft's request abstraction
     * Falls back to $_COOKIE for console requests or when request is unavailable
     */
    private function getCookieValue(string $name): ?string
    {
        // Check if we're in a web request context
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $cookies = Craft::$app->getRequest()->getCookies();
            $cookie = $cookies->get($name);
            if ($cookie !== null) {
                return $this->validateCookieValue($name, $cookie->value);
            }
        }

        // Fallback to $_COOKIE for newly set cookies in same request
        // (Craft's cookie collection doesn't include cookies set via setcookie())
        $value = $_COOKIE[$name] ?? null;

        return $value !== null ? $this->validateCookieValue($name, $value) : null;
    }

    /**
     * Validate cookie value format to prevent injection attacks
     */
    private function validateCookieValue(string $name, string $value): ?string
    {
        // Validate visitor ID cookie (must be UUID format)
        if ($name === self::VISITOR_ID_COOKIE) {
            // UUID format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx (36 chars)
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value)) {
                Craft::warning("Invalid visitor ID cookie format detected", __METHOD__);
                return null;
            }
            return $value;
        }

        if ($name === self::MARKER_COOKIE) {
            return $value === '1' ? $value : null;
        }

        // Validate variant cookies (must be 'control' or 'variant')
        if (str_starts_with($name, self::VISITOR_COOKIE_PREFIX)) {
            if (!in_array($value, [Test::VARIANT_CONTROL, Test::VARIANT_VARIANT], true)) {
                Craft::warning("Invalid variant cookie value detected: {$value}", __METHOD__);
                return null;
            }
            return $value;
        }

        // Unknown cookie type - return as-is
        return $value;
    }
}
