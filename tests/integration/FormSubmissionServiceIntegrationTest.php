<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Settings;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;
use livehand\abtestcraft\services\FormSubmissionService;
use DateTime;

/**
 * Server-side counting of smart-mode form goals (Freeform/Formie success events)
 */
class FormSubmissionServiceIntegrationTest extends Unit
{
    private FormSubmissionService $service;

    /** @var int[] */
    private array $testIds = [];

    private string $visitorId;

    /** @var array{0: array{id: int, uri: string}, 1: array{id: int, uri: string}, 2: array{id: int, uri: string}} */
    private array $entries;

    private string $originalMode;

    protected function _before(): void
    {
        $this->service = ABTestCraft::getInstance()->formSubmissions;
        $this->visitorId = StringHelper::UUID();
        $_COOKIE['_abtestcraft_vid'] = $this->visitorId;

        $settings = ABTestCraft::getInstance()->getSettings();
        $this->originalMode = $settings->conversionCountingMode;
        $settings->conversionCountingMode = Settings::COUNTING_PER_GOAL_TYPE;

        $this->entries = $this->getEntriesWithUris();
    }

    protected function _after(): void
    {
        foreach ($this->testIds as $id) {
            DailyStatsRecord::deleteAll(['testId' => $id]);
            VisitorRecord::deleteAll(['testId' => $id]);
            GoalRecord::deleteAll(['testId' => $id]);
            TestRecord::deleteAll(['id' => $id]);
        }
        $this->testIds = [];
        unset($_COOKIE['_abtestcraft_vid']);
        ABTestCraft::getInstance()->getSettings()->conversionCountingMode = $this->originalMode;
        ABTestCraft::getInstance()->getSettings()->trackFormSubmissions = true;
    }

    public function testSelectedFormCountsWhereverSubmitted(): void
    {
        $testId = $this->createRunningTest(['freeform:contact']);
        $this->assignVisitor($testId);

        // Submitted on an untested page (or with no referrer at all)
        $this->assertSame(1, $this->service->recordFormSubmission('freeform', 'contact', ['https://example.com/elsewhere']));
        $this->assertSame(1, $this->conversions($testId));
    }

    public function testUnselectedFormIsIgnored(): void
    {
        $testId = $this->createRunningTest(['freeform:contact']);
        $this->assignVisitor($testId);

        $this->assertSame(0, $this->service->recordFormSubmission('freeform', 'newsletter'));
        $this->assertSame(0, $this->service->recordFormSubmission('formie', 'contact'));
        $this->assertSame(0, $this->conversions($testId));
    }

    /**
     * With no forms selected, any form counts, but only on a tested page:
     * the control entry, one of its child pages or the variant's own URL
     */
    public function testNoFormsSelectedCountsOnlyOnTestedPages(): void
    {
        $testId = $this->createRunningTest([]);
        $this->assignVisitor($testId);
        [$control, , $other] = $this->entries;

        $this->assertSame(0, $this->service->recordFormSubmission('formie', 'anything', [$this->url($other['uri'])]));
        $this->assertSame(0, $this->service->recordFormSubmission('formie', 'anything', []));
        $this->assertSame(1, $this->service->recordFormSubmission('formie', 'anything', [
            $this->url($other['uri']),
            UrlHelper::urlWithParams($this->url($control['uri']), ['utm_source' => 'x']),
        ]));
        $this->assertSame(1, $this->conversions($testId));
    }

    public function testTestedPageUrlRecognisesControlVariantAndChildPages(): void
    {
        $testId = $this->createRunningTest([]);
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);
        [$control, $variant, $other] = $this->entries;

        $this->assertTrue($this->service->isTestedPageUrl($test, $this->url($control['uri'])));
        $this->assertTrue($this->service->isTestedPageUrl($test, $this->url($variant['uri'])));
        $this->assertFalse($this->service->isTestedPageUrl($test, $this->url($other['uri'])));
        $this->assertFalse($this->service->isTestedPageUrl($test, 'not a url'));

        // Rewritten, index.php/ and ?p= URL styles all resolve to the same page
        $base = rtrim((string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
        $this->assertTrue($this->service->isTestedPageUrl($test, "{$base}/{$control['uri']}"));
        $this->assertTrue($this->service->isTestedPageUrl($test, "{$base}/index.php/{$control['uri']}?x=1"));
        $this->assertTrue($this->service->isTestedPageUrl($test, "{$base}/index.php?p={$control['uri']}"));
        $this->assertFalse($this->service->isTestedPageUrl($test, "{$base}/index.php?p={$other['uri']}"));

        // A child page mapped by the cascade
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $now = (new DateTime())->format('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert('{{%abtestcraft_test_descendants}}', [
            'testId' => $testId, 'controlEntryId' => $control['id'], 'descendantEntryId' => $other['id'],
            'variantAncestorId' => $variant['id'], 'depth' => 1, 'siteId' => $siteId,
            'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
        ])->execute();
        $this->assertTrue($this->service->isTestedPageUrl($test, $this->url($other['uri'])));
    }

    public function testVisitorNotAssignedOrTestNotRunningIsIgnored(): void
    {
        $running = $this->createRunningTest(['freeform:contact']);
        $paused = $this->createRunningTest(['freeform:contact'], Test::STATUS_PAUSED);
        $this->assignVisitor($paused);

        // Assigned only to the paused test; not assigned to the running one
        $this->assertSame(0, $this->service->recordFormSubmission('freeform', 'contact'));
        $this->assertSame(0, $this->conversions($running));
        $this->assertSame(0, $this->conversions($paused));

        $this->assignVisitor($running);
        unset($_COOKIE['_abtestcraft_vid']);
        $this->assertSame(0, $this->service->recordFormSubmission('freeform', 'contact'), 'No visitor cookie');
    }

    public function testCountsForEveryRunningTestTheVisitorIsIn(): void
    {
        $a = $this->createRunningTest(['freeform:contact']);
        $b = $this->createRunningTest(['freeform:contact', 'formie:quote']);
        $this->assignVisitor($a);
        $this->assignVisitor($b);

        $this->assertSame(2, $this->service->recordFormSubmission('freeform', 'contact'));
        $this->assertSame(1, $this->conversions($a));
        $this->assertSame(1, $this->conversions($b));
    }

    public function testDuplicateSubmissionCountsOnceInPerGoalTypeMode(): void
    {
        $testId = $this->createRunningTest(['freeform:contact']);
        $this->assignVisitor($testId);

        $this->service->recordFormSubmission('freeform', 'contact');
        $this->service->recordFormSubmission('freeform', 'contact');
        $this->assertSame(1, $this->conversions($testId));
    }

    public function testAdvancedModeAndDisabledGoalsAreLeftToTheBrowser(): void
    {
        $advanced = $this->createRunningTest(null);
        $disabled = $this->createRunningTest(['freeform:contact'], Test::STATUS_RUNNING, false);
        $this->assignVisitor($advanced);
        $this->assignVisitor($disabled);

        $this->assertSame(0, $this->service->recordFormSubmission('freeform', 'contact'));
    }

    public function testTrackFormSubmissionsSettingOffCountsNothing(): void
    {
        $testId = $this->createRunningTest(['freeform:contact']);
        $this->assignVisitor($testId);
        ABTestCraft::getInstance()->getSettings()->trackFormSubmissions = false;

        $this->assertSame(0, $this->service->recordFormSubmission('freeform', 'contact'));
    }

    /**
     * The tracking endpoint refuses browser reports of smart-mode form goals
     * (counted on the server), but still accepts advanced-mode ones
     */
    public function testEndpointRefusesSmartModeFormReports(): void
    {
        $smart = $this->createRunningTest(['freeform:contact']);
        $advanced = $this->createRunningTest(null);
        $this->assignVisitor($smart);
        $this->assignVisitor($advanced);
        $tracking = ABTestCraft::getInstance()->tracking;

        $this->assertFalse($tracking->recordConversionByHandle($this->handle($smart), Goal::TYPE_FORM));
        $this->assertSame(0, $this->conversions($smart));
        $this->assertTrue($tracking->recordConversionByHandle($this->handle($smart), Goal::TYPE_PHONE));

        $this->assertTrue($tracking->recordConversionByHandle($this->handle($advanced), Goal::TYPE_FORM));
        $this->assertSame(1, $this->conversions($advanced, Goal::TYPE_FORM));
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @param string[]|null $forms Selected forms (smart mode), or null for advanced mode
     */
    private function createRunningTest(?array $forms, string $status = Test::STATUS_RUNNING, bool $goalEnabled = true): int
    {
        [$control, $variant] = $this->entries;

        $record = new TestRecord();
        $record->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $record->name = 'Form submission test';
        $record->handle = 'formSubmissionTest' . StringHelper::randomString(8);
        $record->status = $status;
        $record->controlEntryId = $control['id'];
        $record->variantEntryId = $variant['id'];
        $record->trafficSplit = 50;
        $record->goalType = Goal::TYPE_FORM;
        $record->save(false);
        $this->testIds[] = (int) $record->id;

        $goal = new GoalRecord();
        $goal->testId = $record->id;
        $goal->goalType = Goal::TYPE_FORM;
        $goal->isEnabled = $goalEnabled;
        $goal->config = $forms === null
            ? ['mode' => Goal::MODE_ADVANCED, 'formSelector' => '#contact', 'successMethod' => Goal::SUCCESS_ANY]
            : ['mode' => Goal::MODE_SMART, 'forms' => $forms];
        $goal->sortOrder = 0;
        $goal->save(false);

        return (int) $record->id;
    }

    private function assignVisitor(int $testId): void
    {
        $_COOKIE['_abtestcraft_vid'] = $this->visitorId;

        $visitor = new VisitorRecord();
        $visitor->testId = $testId;
        $visitor->visitorId = $this->visitorId;
        $visitor->variant = Test::VARIANT_VARIANT;
        $visitor->converted = false;
        $visitor->save(false);
    }

    private function conversions(int $testId, ?string $goalType = null): int
    {
        return (int) (new Query())
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $testId, 'goalType' => $goalType])
            ->sum('conversions');
    }

    private function handle(int $testId): string
    {
        return (string) TestRecord::findOne($testId)->handle;
    }

    private function url(string $uri): string
    {
        return UrlHelper::siteUrl($uri, null, null, Craft::$app->getSites()->getPrimarySite()->id);
    }

    /**
     * Three live entries with URIs on the primary site (control, variant, other)
     *
     * @return array{0: array{id: int, uri: string}, 1: array{id: int, uri: string}, 2: array{id: int, uri: string}}
     */
    private function getEntriesWithUris(): array
    {
        $rows = (new Query())
            ->select(['es.elementId', 'es.uri'])
            ->from(['es' => '{{%elements_sites}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[es.elementId]]')
            ->innerJoin(['en' => '{{%entries}}'], '[[en.id]] = [[e.id]]')
            ->where([
                'es.siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'e.dateDeleted' => null,
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.enabled' => true,
                'es.enabled' => true,
            ])
            ->andWhere(['not', ['es.uri' => null]])
            ->andWhere(['not', ['es.uri' => '__home__']])
            ->andWhere(['not like', 'es.uri', '{'])
            ->orderBy(['es.elementId' => SORT_ASC])
            ->limit(3)
            ->all();

        if (count($rows) < 3) {
            $this->markTestSkipped('Needs three entries with URLs on the primary site');
        }

        return array_map(fn($r) => ['id' => (int) $r['elementId'], 'uri' => (string) $r['uri']], $rows);
    }
}
