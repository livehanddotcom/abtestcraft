<?php

declare(strict_types=1);

namespace livehand\abtestcraft\variables;

use craft\elements\Entry;
use livehand\abtestcraft\ABTestCraft;

/**
 * ABTestCraft Twig variable class
 *
 * Provides access to plugin services in Twig templates via craft.abtestcraft
 */
class ABTestCraftVariable
{
    // =========================================================================
    // CASCADE / NESTED ENTRY HELPERS
    // =========================================================================

    /**
     * Get the effective parent for an entry, accounting for split test cascade
     *
     * If the current page is a cascaded child of a tested parent, and the visitor
     * is assigned to the variant, this returns the variant parent instead of
     * the original parent.
     *
     * Usage: {% set parent = craft.abtestcraft.getParent(entry) %}
     */
    public function getParent(Entry $entry): ?Entry
    {
        $routing = ABTestCraft::getInstance()->routing;

        // If this is a cascaded page and visitor is seeing variant
        if ($routing->isCascaded() && $routing->isShowingVariant()) {
            $variantParent = $routing->getVariantParent();
            if ($variantParent) {
                return $variantParent;
            }
        }

        // Return normal parent
        return $entry->getParent();
    }

    /**
     * Get children for an entry, accounting for split test variant borrowing
     *
     * If the entry is a variant in a running test, this returns the control
     * entry's children so navigation works properly.
     *
     * Usage: {% set children = craft.abtestcraft.getChildren(entry) %}
     */
    public function getChildren(Entry $entry): array
    {
        $cascade = ABTestCraft::getInstance()->cascade;

        // Check if this entry is a variant in a running test
        $test = $cascade->isVariantEntry($entry->id, $entry->siteId);

        if ($test) {
            // Return the control entry's children instead
            return $cascade->getControlChildren($entry);
        }

        // Return normal children
        return $entry->getChildren()->all();
    }

    /**
     * Check if the current page is a cascaded descendant of a tested entry
     *
     * Usage: {% if craft.abtestcraft.isCascaded() %}...{% endif %}
     */
    public function isCascaded(): bool
    {
        return ABTestCraft::getInstance()->routing->isCascaded();
    }

    /**
     * Check if an entry is a variant entry in a running test
     *
     * Usage: {% if craft.abtestcraft.isVariantEntry(entry) %}...{% endif %}
     */
    public function isVariantEntry(Entry $entry): bool
    {
        return ABTestCraft::getInstance()->cascade->isVariantEntry($entry->id, $entry->siteId) !== null;
    }

    /**
     * Get the currently active variant assignment ('control' or 'variant')
     *
     * Usage: {% set variant = craft.abtestcraft.getActiveVariant() %}
     */
    public function getActiveVariant(): ?string
    {
        return ABTestCraft::getInstance()->routing->getActiveVariant();
    }

    /**
     * Check if there's an active test on the current page
     *
     * Usage: {% if craft.abtestcraft.hasActiveTest() %}...{% endif %}
     */
    public function hasActiveTest(): bool
    {
        return ABTestCraft::getInstance()->routing->hasActiveTest();
    }

    /**
     * Check if visitor is seeing the variant
     *
     * Usage: {% if craft.abtestcraft.isShowingVariant() %}...{% endif %}
     */
    public function isShowingVariant(): bool
    {
        return ABTestCraft::getInstance()->routing->isShowingVariant();
    }

    /**
     * Check if visitor is seeing the control
     *
     * Usage: {% if craft.abtestcraft.isShowingControl() %}...{% endif %}
     */
    public function isShowingControl(): bool
    {
        return ABTestCraft::getInstance()->routing->isShowingControl();
    }

    // =========================================================================
    // FORM PLUGIN DETECTION
    // =========================================================================

    /**
     * Get form plugin detection info for the goal configuration UI
     */
    public function getFormPlugins(): array
    {
        $detector = ABTestCraft::getInstance()->formPluginDetector;

        return [
            'installed' => $detector->getInstalledFormPlugins(),
            'allForms' => $detector->getAllAvailableForms(),
            'hasPlugin' => $detector->hasFormPlugin(),
        ];
    }

    /**
     * Get all available forms from installed form plugins
     */
    public function getAvailableForms(): array
    {
        return ABTestCraft::getInstance()->formPluginDetector->getAllAvailableForms();
    }

    /**
     * Check if any form plugin is installed
     */
    public function hasFormPlugin(): bool
    {
        return ABTestCraft::getInstance()->formPluginDetector->hasFormPlugin();
    }

    /**
     * Get installed form plugins
     */
    public function getInstalledFormPlugins(): array
    {
        return ABTestCraft::getInstance()->formPluginDetector->getInstalledFormPlugins();
    }

    /**
     * Build a form selector for a specific plugin and form handle
     */
    public function buildFormSelector(string $plugin, string $handle): string
    {
        return ABTestCraft::getInstance()->formPluginDetector->buildFormSelector($plugin, $handle);
    }

    /**
     * Get success selectors for a plugin
     */
    public function getSuccessSelectors(string $plugin): array
    {
        return ABTestCraft::getInstance()->formPluginDetector->getSuccessSelectors($plugin);
    }
}
