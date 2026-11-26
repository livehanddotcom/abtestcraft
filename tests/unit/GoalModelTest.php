<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\models\Goal;

/**
 * Goal matching used by server-side counting (form and page-visit goals)
 */
class GoalModelTest extends Unit
{
    public function testCoversFormWithSelectedForms(): void
    {
        $goal = $this->formGoal(['freeform:contact', 'formie:quote']);

        $this->assertTrue($goal->hasSelectedForms());
        $this->assertTrue($goal->coversForm('freeform', 'contact'));
        $this->assertTrue($goal->coversForm('formie', 'quote'));
        $this->assertFalse($goal->coversForm('formie', 'contact'));
        $this->assertFalse($goal->coversForm('freeform', 'newsletter'));
    }

    public function testCoversAnyFormWhenNoneSelected(): void
    {
        $goal = $this->formGoal([]);

        $this->assertFalse($goal->hasSelectedForms());
        $this->assertTrue($goal->coversForm('freeform', 'anything'));
        $this->assertTrue($goal->coversForm('formie', 'anything'));
    }

    /**
     * Same rules as the tracking script's urlMatchesPath()
     */
    public function testMatchesPageUrlExact(): void
    {
        $goal = $this->pageGoal('/thank-you', Goal::MATCH_EXACT);

        $this->assertTrue($goal->matchesPageUrl('https://example.com/thank-you'));
        $this->assertTrue($goal->matchesPageUrl('https://example.com/Thank-You?ref=form#top'));
        $this->assertTrue($goal->matchesPageUrl('/thank-you'));
        $this->assertFalse($goal->matchesPageUrl('https://example.com/thank-you/extra'));
        $this->assertFalse($goal->matchesPageUrl('https://example.com/contact'));
    }

    public function testMatchesPageUrlStartsWithAndContains(): void
    {
        $startsWith = $this->pageGoal('/shop/checkout', Goal::MATCH_STARTS_WITH);
        $this->assertTrue($startsWith->matchesPageUrl('https://example.com/shop/checkout/complete'));
        $this->assertFalse($startsWith->matchesPageUrl('https://example.com/en/shop/checkout'));

        $contains = $this->pageGoal('confirmed', Goal::MATCH_CONTAINS);
        $this->assertTrue($contains->matchesPageUrl('https://example.com/booking/confirmed/123'));
        $this->assertFalse($contains->matchesPageUrl('https://example.com/booking?status=confirmed'));
    }

    public function testGoalUrlWithHostIsComparedByPath(): void
    {
        $goal = $this->pageGoal('https://example.com/thank-you', Goal::MATCH_EXACT);

        $this->assertTrue($goal->matchesPageUrl('https://www.example.org/thank-you'));
    }

    /**
     * A test on a site under a path (/fr/) can use the site's own paths
     */
    public function testGoalMatchesRelativeToTheSitePath(): void
    {
        $goal = $this->pageGoal('/thank-you', Goal::MATCH_EXACT);
        $this->assertTrue($goal->matchesPageUrl('https://example.com/fr/thank-you', 'fr'));
        $this->assertTrue($goal->matchesPageUrl('https://example.com/fr/thank-you', '/fr/'));
        $this->assertFalse($goal->matchesPageUrl('https://example.com/fr/thank-you'), 'Without the site path');
        $this->assertFalse($goal->matchesPageUrl('https://example.com/de/thank-you', 'fr'));
        $this->assertFalse($goal->matchesPageUrl('https://example.com/french/thank-you', 'fr'));

        $prefixed = $this->pageGoal('/fr/thank-you', Goal::MATCH_EXACT);
        $this->assertTrue($prefixed->matchesPageUrl('https://example.com/fr/thank-you', 'fr'), 'Full path still works');

        $home = $this->pageGoal('/', Goal::MATCH_EXACT);
        $this->assertTrue($home->matchesPageUrl('https://example.com/fr', 'fr'));
    }

    public function testEmptyGoalUrlMatchesNothing(): void
    {
        $this->assertFalse($this->pageGoal('', Goal::MATCH_CONTAINS)->matchesPageUrl('https://example.com/'));
        $this->assertFalse((new Goal(['goalType' => Goal::TYPE_PAGE]))->matchesPageUrl('https://example.com/'));
    }

    /**
     * @param string[] $forms
     */
    private function formGoal(array $forms): Goal
    {
        return new Goal([
            'goalType' => Goal::TYPE_FORM,
            'config' => ['mode' => Goal::MODE_SMART, 'forms' => $forms],
        ]);
    }

    private function pageGoal(string $url, string $matchType): Goal
    {
        $goal = new Goal(['goalType' => Goal::TYPE_PAGE]);
        $goal->setPageConfig($url, $matchType);

        return $goal;
    }
}
