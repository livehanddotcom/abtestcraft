<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\db\EagerLoadPlan;
use craft\events\TemplateEvent;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\ABTestCraft;

/**
 * Routing service - handles template/content swapping for split tests
 */
class RoutingService extends Component
{
    private ?Test $activeTest = null;
    private ?string $activeVariant = null;
    private bool $isCascaded = false;
    private ?array $cascadeInfo = null;
    private ?Entry $controlEntry = null;
    private ?Entry $variantParent = null;
    private bool $isEditorPreview = false;

    /**
     * Handle the before render event to swap content if needed
     */
    public function handleBeforeRender(TemplateEvent $event): void
    {
        // Only process front-end requests
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        // Get the matched element
        $element = Craft::$app->getUrlManager()->getMatchedElement();

        if (!$element instanceof Entry) {
            return;
        }

        // Check if there's an active test for this entry (direct match)
        $test = ABTestCraft::getInstance()->tests->getTestByControlEntryId(
            $element->id,
            $element->siteId
        );

        $this->isCascaded = false;
        $this->cascadeInfo = null;

        // If no direct test, check if this is a descendant of a tested entry (cascade)
        if (!$test || !$test->isRunning()) {
            $cascadeInfo = ABTestCraft::getInstance()->cascade->getCascadeInfo(
                $element->id,
                $element->siteId
            );

            if ($cascadeInfo) {
                $test = ABTestCraft::getInstance()->tests->getTestById($cascadeInfo['testId']);
                if ($test && $test->isRunning()) {
                    $this->isCascaded = true;
                    $this->cascadeInfo = $cascadeInfo;
                } else {
                    $test = null;
                }
            }
        }

        if (!$test || !$test->isRunning()) {
            // No running test here: the page may be showing a finished
            // test's winning variant to everyone
            $this->showServedWinner($event, $element);
            return;
        }

        $pageCache = ABTestCraft::getInstance()->pageCache;

        // The response depends on the visitor's variant cookie, so it must
        // never be stored in a shared/full-page cache (Blitz, CDN, proxy)
        $pageCache->excludeCurrentRequest();

        // Blitz's cache generator isn't a real visitor: render the control
        // without assigning a variant, setting cookies, or counting an impression
        if ($pageCache->isCacheGeneratorRequest()) {
            return;
        }

        // Without its variant entry (trashed, or not on this site) a test can't
        // show the variant, and counting visitors as "variant" while they see
        // the original would corrupt the results
        if (!$test->getVariantEntry()) {
            Craft::warning(
                "Test '{$test->handle}' is running but its variant entry is missing; nobody is assigned",
                __METHOD__
            );
            return;
        }

        $filter = ABTestCraft::getInstance()->visitorFilter;
        $previewVariant = $filter->getEditorPreviewVariant();

        if ($previewVariant !== null) {
            // An editor previewing a variant: shown, not assigned or counted
            $variant = $previewVariant;
            $this->isEditorPreview = true;
        } elseif ($filter->shouldExcludeRequest()) {
            // Previews, bots, control panel users and visitors without consent
            // see the control, with no assignment, cookies or impression.
            // Visitors who withdrew consent also lose their cookies.
            ABTestCraft::getInstance()->consent->removeCookiesWithoutConsent();
            return;
        } else {
            $variant = ABTestCraft::getInstance()->assignment->getOrAssignVariant($test);
        }

        // Store for later use (tracking, SEO, Twig helpers)
        $this->activeTest = $test;
        $this->activeVariant = $variant;

        // Handle based on whether this is a direct test or cascaded
        if ($variant === Test::VARIANT_VARIANT) {
            if ($this->isCascaded) {
                // Cascaded page - visitor is on a child page of a tested parent
                $this->variantParent = Entry::find()
                    ->id($this->cascadeInfo['variantAncestorId'])
                    ->siteId($element->siteId)
                    ->status(null)
                    ->one();

                // Automatically override entry.parent to return variant parent
                // This makes {{ entry.parent }} work without template changes
                if ($this->variantParent) {
                    $this->overrideEntryParent($element, $this->variantParent);
                }
            } else {
                // Direct test - swap to variant entry
                $this->controlEntry = $element;
                $this->swapToVariant($event, $test);
            }
        }

        if (!$this->isEditorPreview) {
            ABTestCraft::getInstance()->tracking->recordImpression($test, $variant);
        }
    }

    /**
     * Show a completed test's winning variant to everyone, on the original
     * page and its child pages. Everyone gets the same page, so there are no
     * cookies or tracking, and the page can be cached. Previews show the
     * entry being previewed; editors can see the original with
     * ?abtestcraft=control.
     */
    private function showServedWinner(TemplateEvent $event, Entry $element): void
    {
        $filter = ABTestCraft::getInstance()->visitorFilter;
        if ($filter->isPreviewRequest() || $filter->getEditorPreviewVariant() === Test::VARIANT_CONTROL) {
            return;
        }

        $tests = ABTestCraft::getInstance()->tests;

        $test = $tests->getServingTestByControlEntryId($element->id, $element->siteId);
        if ($test) {
            $this->controlEntry = $element;
            $this->swapToVariant($event, $test);
            return;
        }

        $cascadeInfo = ABTestCraft::getInstance()->cascade->getCascadeInfo($element->id, $element->siteId, true);
        if (!$cascadeInfo) {
            return;
        }

        $test = $tests->getTestById($cascadeInfo['testId']);
        if (!$test || !$test->isServingWinner()) {
            return;
        }

        $variantParent = Entry::find()
            ->id($cascadeInfo['variantAncestorId'])
            ->siteId($element->siteId)
            ->status(null)
            ->one();
        if ($variantParent) {
            $this->overrideEntryParent($element, $variantParent);
        }
    }

    /**
     * Override entry's cached _parent property using Reflection
     * This makes {{ entry.parent }} automatically return the variant parent
     * without requiring any template changes
     */
    private function overrideEntryParent(Entry $entry, Entry $newParent): void
    {
        try {
            // Use Reflection to access the private _parent property
            // Craft's getParent() checks this cached property first
            $reflection = new \ReflectionClass(\craft\base\Element::class);
            $parentProperty = $reflection->getProperty('_parent');
            $parentProperty->setAccessible(true);
            $parentProperty->setValue($entry, $newParent);
        } catch (\Throwable $e) {
            Craft::warning(
                "Split Test: Could not override entry parent via Reflection: " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Override entry's children by setting eager-loaded elements
     * This makes {{ entry.children }} automatically return control's children
     * without requiring any template changes
     */
    private function overrideEntryChildren(Entry $variantEntry, Entry $controlEntry): void
    {
        try {
            // Get the control entry's children
            $controlChildren = $controlEntry->getChildren()->all();

            if (empty($controlChildren)) {
                return;
            }

            // Use setEagerLoadedElements to override what getChildren() returns
            // This is the cleanest way as Craft checks eager-loaded elements first.
            // The plan argument is required on every Craft 5 version; calling it
            // without one threw ArgumentCountError (a 500 for variant visitors
            // whenever the control entry had children).
            $variantEntry->setEagerLoadedElements(
                'children',
                $controlChildren,
                new EagerLoadPlan(['handle' => 'children', 'alias' => 'children'])
            );
        } catch (\Throwable $e) {
            Craft::warning(
                "Split Test: Could not override entry children: " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Swap the template/content to the variant entry
     */
    private function swapToVariant(TemplateEvent $event, Test $test): void
    {
        $variantEntry = $test->getVariantEntry();
        $controlEntry = $this->controlEntry;

        if (!$variantEntry) {
            Craft::warning("Split Test: Variant entry not found for test '{$test->handle}'", __METHOD__);
            return;
        }

        // Get the variant's section template settings
        $section = $variantEntry->getSection();
        $siteSettings = $section->getSiteSettings();
        $siteId = $variantEntry->siteId;

        if (isset($siteSettings[$siteId])) {
            $templatePath = $siteSettings[$siteId]->template;

            if ($templatePath) {
                // Swap template
                $event->template = $templatePath;
            }
        }

        // Automatically override entry.children to return control's children
        // This makes {{ entry.children }} work without template changes
        if ($controlEntry) {
            $this->overrideEntryChildren($variantEntry, $controlEntry);
        }

        // Swap the entry variable
        if (isset($event->variables['entry'])) {
            $event->variables['entry'] = $variantEntry;
        }

        // Also set as 'element' if that's used
        if (isset($event->variables['element'])) {
            $event->variables['element'] = $variantEntry;
        }
    }

    /**
     * Get the currently active test (if any)
     */
    public function getActiveTest(): ?Test
    {
        return $this->activeTest;
    }

    /**
     * Get the currently active variant (if any)
     */
    public function getActiveVariant(): ?string
    {
        return $this->activeVariant;
    }

    /**
     * Check if there's an active test on this request
     */
    public function hasActiveTest(): bool
    {
        return $this->activeTest !== null;
    }

    /**
     * Whether this page view is an editor previewing a variant with
     * ?abtestcraft=variant|control (not assigned, tracked or counted)
     */
    public function isEditorPreview(): bool
    {
        return $this->isEditorPreview;
    }

    /**
     * Check if current visitor is seeing the variant
     */
    public function isShowingVariant(): bool
    {
        return $this->activeVariant === Test::VARIANT_VARIANT;
    }

    /**
     * Check if current visitor is seeing the control
     */
    public function isShowingControl(): bool
    {
        return $this->activeVariant === Test::VARIANT_CONTROL;
    }

    /**
     * Check if current page is a cascaded descendant of a tested entry
     */
    public function isCascaded(): bool
    {
        return $this->isCascaded;
    }

    /**
     * Get cascade info for the current page (if cascaded)
     */
    public function getCascadeInfo(): ?array
    {
        return $this->cascadeInfo;
    }

    /**
     * Get the variant parent entry for cascaded pages
     * Used by Twig helpers to return variant parent in place of control parent
     */
    public function getVariantParent(): ?Entry
    {
        return $this->variantParent;
    }

    /**
     * Get the control entry when showing variant
     * Used by Twig helpers to "borrow" children from control
     */
    public function getControlEntry(): ?Entry
    {
        return $this->controlEntry;
    }
}
