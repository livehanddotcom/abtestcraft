<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\web\View;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\ABTestCraft;

/**
 * SEO service - handles noindex/canonical for variant pages
 */
class SeoService extends Component
{
    /**
     * Inject SEO protection for variant entries
     * - Adds noindex to prevent variant from being indexed
     * - Adds canonical pointing to the control entry
     */
    public function injectSeoProtection(Entry $entry): void
    {
        // Check if this entry is a variant in any active test
        $test = $this->findTestWhereEntryIsVariant($entry);

        if (!$test) {
            return;
        }

        $controlEntry = $test->getControlEntry();

        if (!$controlEntry) {
            return;
        }

        $controlUrl = $controlEntry->getUrl();

        // Inject noindex meta tag
        Craft::$app->getView()->registerMetaTag([
            'name' => 'robots',
            'content' => 'noindex,follow',
        ], 'abtestcraft-robots');

        // Inject canonical link
        Craft::$app->getView()->registerLinkTag([
            'rel' => 'canonical',
            'href' => $controlUrl,
        ], 'abtestcraft-canonical');
    }

    /**
     * Find a test where the given entry is the variant
     * Uses direct query to avoid N+1 problem
     */
    private function findTestWhereEntryIsVariant(Entry $entry): ?Test
    {
        // Direct query instead of iterating all tests
        $record = TestRecord::find()
            ->where([
                'variantEntryId' => $entry->id,
                'siteId' => $entry->siteId,
                'status' => Test::STATUS_RUNNING,
            ])
            ->one();

        if (!$record) {
            return null;
        }

        return ABTestCraft::getInstance()->tests->getTestById($record->id);
    }

    /**
     * Check if an entry is a variant in any active test
     */
    public function isVariantEntry(Entry $entry): bool
    {
        // Direct query for existence check
        return TestRecord::find()
            ->where([
                'variantEntryId' => $entry->id,
                'siteId' => $entry->siteId,
                'status' => Test::STATUS_RUNNING,
            ])
            ->exists();
    }

    /**
     * Check if an entry is a control in any active test
     */
    public function isControlEntry(Entry $entry): bool
    {
        // Direct query for existence check
        return TestRecord::find()
            ->where([
                'controlEntryId' => $entry->id,
                'siteId' => $entry->siteId,
                'status' => Test::STATUS_RUNNING,
            ])
            ->exists();
    }
}
