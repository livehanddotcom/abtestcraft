<?php

declare(strict_types=1);

namespace livehand\abtestcraft\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\RateLimitRecord;
use livehand\abtestcraft\ABTestCraft;
use yii\web\Response;

/**
 * Track controller - handles conversion tracking from frontend
 */
class TrackController extends Controller
{
    /**
     * Allow anonymous access to tracking endpoints
     */
    protected array|int|bool $allowAnonymous = ['convert', 'page'];

    /**
     * Skip CSRF validation for the anonymous conversion beacon.
     *
     * A CSRF token embedded in the page goes stale when the page is served
     * from any page cache (Blitz, CDN), silently dropping every conversion.
     * CSRF adds no protection here: a conversion is only recorded for the
     * visitor ID in the requester's own first-party cookie (SameSite=Lax, so
     * not sent on cross-site POSTs), and the endpoint is rate limited.
     */
    public function beforeAction($action): bool
    {
        if (in_array($action->id, ['convert', 'page'], true)) {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Record a conversion
     */
    public function actionConvert(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $testHandle = $request->getBodyParam('testHandle');
        $conversionType = $request->getBodyParam('conversionType');
        $goalId = $request->getBodyParam('goalId');

        if (!$testHandle || !$conversionType) {
            return $this->asJson(['success' => false, 'error' => 'Missing parameters']);
        }

        // Validate testHandle format (same rule as test handles themselves)
        if (!is_string($testHandle) || !preg_match(Test::HANDLE_PATTERN, $testHandle)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid test handle format']);
        }

        // Whitelist allowed conversion types
        $allowedTypes = ['form', 'phone', 'email', 'download', 'page', 'custom'];
        if (!is_string($conversionType) || !in_array($conversionType, $allowedTypes, true)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid conversion type']);
        }

        // Validate goalId is a positive integer if provided
        if ($goalId !== null && $goalId !== '' && (!is_numeric($goalId) || (int)$goalId < 1)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid goal ID']);
        }

        Craft::debug("Conversion request: test={$testHandle}, type={$conversionType}", 'abtestcraft');

        // Bots, control panel users and visitors without consent aren't counted
        if (ABTestCraft::getInstance()->visitorFilter->shouldExcludeRequest()) {
            ABTestCraft::getInstance()->consent->removeCookiesWithoutConsent();
            return $this->asJson(['success' => false]);
        }

        // Only assigned visitors can convert. Reading the ID without creating
        // one means a request from anyone else doesn't hand them a cookie.
        $visitorId = ABTestCraft::getInstance()->assignment->getVisitorId();
        if ($visitorId === null) {
            return $this->asJson(['success' => false]);
        }

        // Rate limiting - combine IP + visitor ID for stronger protection
        $ip = $request->getUserIP();

        // Support Cloudflare's CF-Connecting-IP header
        $cfIp = $request->getHeaders()->get('CF-Connecting-IP');
        $effectiveIp = $cfIp ?: $ip;

        // Check rate limit using database-based approach (multi-server compatible)
        if (!$this->checkRateLimit($effectiveIp, $visitorId, $testHandle)) {
            Craft::warning("Rate limit exceeded: IP {$effectiveIp} for test {$testHandle}", 'abtestcraft');
            return $this->asJson(['success' => false, 'error' => 'Rate limited']);
        }

        // Record the conversion
        $success = ABTestCraft::getInstance()->tracking->recordConversionByHandle(
            $testHandle,
            $conversionType,
            $goalId ? (int) $goalId : null
        );

        return $this->asJson(['success' => $success]);
    }

    /**
     * Record a page visit from the beacon on every front-end page (see
     * PageVisitService). Counts page-visit goals for the visitor's running
     * tests, and returns dataLayer events for them when the dataLayer is on.
     */
    public function actionPage(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $url = $request->getBodyParam('url');
        if (!is_string($url) || $url === '' || strlen($url) > 2048) {
            return $this->asJson(['success' => false]);
        }

        // Never create a visitor ID here: only assigned visitors can convert
        $visitorId = ABTestCraft::getInstance()->assignment->getVisitorId();
        if ($visitorId === null || ABTestCraft::getInstance()->visitorFilter->shouldExcludeRequest()) {
            // A visitor who withdrew consent loses their cookies here: the
            // page script still calls in while the marker cookie exists
            ABTestCraft::getInstance()->consent->removeCookiesWithoutConsent();
            return $this->asJson(['success' => false]);
        }

        $ip = $request->getHeaders()->get('CF-Connecting-IP') ?: $request->getUserIP();
        if (!$this->checkRateLimit((string) $ip, $visitorId, '__page__')) {
            return $this->asJson(['success' => false, 'error' => 'Rate limited']);
        }

        $counted = ABTestCraft::getInstance()->pageVisits->recordPageVisit($url);

        $events = [];
        if (ABTestCraft::getInstance()->getSettings()->enableDataLayer) {
            foreach ($counted as $conversion) {
                $events[] = [
                    'event' => 'abtestcraft_conversion',
                    'abtestcraft_name' => $conversion['testHandle'],
                    'abtestcraft_variant' => $conversion['variant'],
                    'conversion_type' => 'page',
                ];
            }
        }

        return $this->asJson(['success' => true, 'events' => $events]);
    }

    /**
     * Check and increment rate limit using database
     *
     * Uses database for multi-server compatibility. Falls back gracefully
     * if database is unavailable (allows request through).
     *
     * @param string $ip Client IP address
     * @param string $visitorId Visitor ID from cookie
     * @param string $testHandle Test handle
     * @return bool True if request is allowed, false if rate limited
     */
    private function checkRateLimit(string $ip, string $visitorId, string $testHandle): bool
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        $rateLimit = $settings->conversionRateLimit ?? 10;

        // Build cache key
        $cacheKey = "abtestcraft_rate_{$ip}_{$visitorId}_{$testHandle}";

        // Use 60 second sliding window
        $windowDuration = 60;
        $now = new DateTime();
        $windowStart = (new DateTime())->modify("-{$windowDuration} seconds");

        try {
            // First, clean up old records (older than 5 minutes) - do this occasionally
            if (random_int(1, 100) === 1) {
                $this->cleanupOldRateLimits();
            }

            // Check existing record
            $record = RateLimitRecord::find()
                ->where(['cacheKey' => $cacheKey])
                ->one();

            if ($record) {
                $recordWindowStart = new DateTime($record->windowStart);

                // If window has expired, reset the counter
                if ($recordWindowStart < $windowStart) {
                    $record->requestCount = 1;
                    $record->windowStart = $now->format('Y-m-d H:i:s');
                    $record->save();
                    return true;
                }

                // Check if over limit
                if ($record->requestCount >= $rateLimit) {
                    return false;
                }

                // Increment counter
                $record->requestCount++;
                $record->save();
                return true;
            }

            // No existing record - create new one
            $record = new RateLimitRecord();
            $record->cacheKey = $cacheKey;
            $record->requestCount = 1;
            $record->windowStart = $now->format('Y-m-d H:i:s');
            $record->save();

            return true;
        } catch (\Throwable $e) {
            // If database fails, log and allow request (fail open)
            Craft::error("Rate limit check failed: " . $e->getMessage(), 'abtestcraft');
            return true;
        }
    }

    /**
     * Clean up old rate limit records
     *
     * Removes records with window_start older than 5 minutes
     */
    private function cleanupOldRateLimits(): void
    {
        try {
            $cutoff = (new DateTime())->modify('-5 minutes')->format('Y-m-d H:i:s');

            Craft::$app->getDb()->createCommand()
                ->delete('{{%abtestcraft_rate_limits}}', ['<', 'windowStart', $cutoff])
                ->execute();
        } catch (\Throwable $e) {
            Craft::error("Rate limit cleanup failed: " . $e->getMessage(), 'abtestcraft');
        }
    }
}
