<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use livehand\abtestcraft\models\Test;

/**
 * Audit service - logs important split test events for audit trail
 *
 * All audit events are logged to a dedicated 'abtestcraft-audit' log category,
 * which can be configured in Craft's logging configuration to write to a
 * separate file or external logging service.
 */
class AuditService extends Component
{
    private const LOG_CATEGORY = 'abtestcraft-audit';

    /**
     * Log a test creation event
     */
    public function logTestCreated(Test $test): void
    {
        $this->log('created', $test, [
            'name' => $test->name,
            'handle' => $test->handle,
            'controlEntryId' => $test->controlEntryId,
            'variantEntryId' => $test->variantEntryId,
        ]);
    }

    /**
     * Log a test update event
     */
    public function logTestUpdated(Test $test, array $changedAttributes = []): void
    {
        $this->log('updated', $test, [
            'changedAttributes' => $changedAttributes,
        ]);
    }

    /**
     * Log a test started event
     */
    public function logTestStarted(Test $test): void
    {
        $this->log('started', $test, [
            'trafficSplit' => $test->trafficSplit,
            'enabledGoals' => count($test->getEnabledGoals()),
        ]);
    }

    /**
     * Log a test paused event
     */
    public function logTestPaused(Test $test): void
    {
        $this->log('paused', $test);
    }

    /**
     * Log a test completed event
     */
    public function logTestCompleted(Test $test): void
    {
        $stats = null;
        try {
            $stats = \livehand\abtestcraft\ABTestCraft::getInstance()->stats->getTestStats($test);
        } catch (\Throwable $e) {
            // Stats might not be available
        }

        $this->log('completed', $test, [
            'winner' => $test->winnerVariant,
            'durationDays' => $test->getDurationDays(),
            'totalImpressions' => $stats ? ($stats['impressions']['control'] + $stats['impressions']['variant']) : null,
            'totalConversions' => $stats ? ($stats['conversions']['control'] + $stats['conversions']['variant']) : null,
            'confidence' => $stats['confidence'] ?? null,
        ]);
    }

    /**
     * Log a test deleted (moved to trash) event
     */
    public function logTestTrashed(Test $test): void
    {
        $this->log('trashed', $test);
    }

    /**
     * Log a test restored from trash event
     */
    public function logTestRestored(Test $test): void
    {
        $this->log('restored', $test);
    }

    /**
     * Log a test permanently deleted event
     */
    public function logTestDeleted(Test $test): void
    {
        $this->log('deleted', $test);
    }

    /**
     * Log a significance reached event
     */
    public function logSignificanceReached(Test $test, float $confidence): void
    {
        $this->log('significance_reached', $test, [
            'confidence' => $confidence,
        ]);
    }

    /**
     * Log a conversion event
     */
    public function logConversion(Test $test, string $variant, string $conversionType, ?int $goalId = null): void
    {
        $this->log('conversion', $test, [
            'variant' => $variant,
            'conversionType' => $conversionType,
            'goalId' => $goalId,
        ]);
    }

    /**
     * Internal logging method
     */
    private function log(string $action, Test $test, array $context = []): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        $username = $user ? $user->username : 'system';

        $message = sprintf(
            "[%s] Test '%s' (ID: %d) %s by %s",
            strtoupper($action),
            $test->handle,
            $test->id ?? 0,
            $action,
            $username
        );

        $logContext = array_merge([
            'testId' => $test->id,
            'testHandle' => $test->handle,
            'testName' => $test->name,
            'siteId' => $test->siteId,
            'action' => $action,
            'user' => $username,
            'userId' => $user?->id,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'ip' => $this->getClientIp(),
        ], $context);

        Craft::info($message . ' | Context: ' . json_encode($logContext), self::LOG_CATEGORY);
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): ?string
    {
        try {
            if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
                return Craft::$app->getRequest()->getUserIP();
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        return null;
    }
}
