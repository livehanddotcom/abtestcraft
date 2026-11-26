<?php

declare(strict_types=1);

namespace livehand\abtestcraft\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\ABTestCraft;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Tests controller - CRUD operations for split tests
 */
class TestsController extends Controller
{
    /**
     * List all tests
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('abtestcraft:viewResults');

        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // Get status filter from query param, default to 'active'
        $statusFilter = $request->getQueryParam('status', 'active');
        if (!in_array($statusFilter, ['active', 'completed', 'all', 'trashed'], true)) {
            $statusFilter = 'active';
        }

        $tests = ABTestCraft::getInstance()->tests->getTestsByStatus($statusFilter, $siteId);

        // Cache test counts for 60 seconds (cleared by actions that move tests between tabs)
        $counts = Craft::$app->getCache()->getOrSet(self::countsCacheKey($siteId), function () use ($siteId) {
            return ABTestCraft::getInstance()->tests->getTestCounts($siteId);
        }, 60);

        // Batch load stats for all tests in a single query (the Trash tab shows none)
        $batchStats = $statusFilter === 'trashed'
            ? []
            : ABTestCraft::getInstance()->stats->getBatchTestStats($tests);

        $testsWithStats = [];
        foreach ($tests as $test) {
            // Get time estimate for running tests
            $timeEstimate = null;
            if ($test->isRunning()) {
                $timeEstimate = ABTestCraft::getInstance()->stats->getTimeEstimate($test);
            }
            $testsWithStats[] = [
                'test' => $test,
                'stats' => $batchStats[$test->id] ?? [],
                'timeEstimate' => $timeEstimate,
            ];
        }

        return $this->renderTemplate('abtestcraft/tests/index', [
            'tests' => $testsWithStats,
            'statusFilter' => $statusFilter,
            'counts' => $counts,
            'licenseInfo' => ABTestCraft::getInstance()->license->getStatusInfo(),
            'canCreateTests' => ABTestCraft::getInstance()->license->canCreateTests(),
            'canManageTests' => Craft::$app->getUser()->checkPermission('abtestcraft:manageTests'),
        ]);
    }

    /**
     * Create new test form
     *
     * After a failed save, actionSave() passes the unsaved test (with its
     * errors) back as route params, so the form re-renders what was typed.
     */
    public function actionNew(): Response
    {
        $this->requirePermission('abtestcraft:manageTests');

        // Check license allows creating tests
        if (!ABTestCraft::getInstance()->license->canCreateTests()) {
            Craft::$app->getSession()->setError(
                Craft::t('abtestcraft', 'A valid license is required to create new tests.')
            );
            return $this->redirect('abtestcraft/tests');
        }

        [$test, $controlEntry, $variantEntry] = $this->getFailedSaveParams();

        if ($test === null) {
            $test = new Test();
            $test->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        return $this->renderTemplate('abtestcraft/tests/_form', [
            'test' => $test,
            'isNew' => true,
            'controlEntry' => $controlEntry,
            'variantEntry' => $variantEntry,
        ]);
    }

    /**
     * Edit test form
     *
     * After a failed save, actionSave() passes the unsaved test (with its
     * errors) back as route params, so the form re-renders what was typed.
     */
    public function actionEdit(int $testId): Response
    {
        $this->requirePermission('abtestcraft:manageTests');

        [$test, $controlEntry, $variantEntry] = $this->getFailedSaveParams();

        if ($test !== null) {
            return $this->renderTemplate('abtestcraft/tests/_form', [
                'test' => $test,
                'isNew' => false,
                'controlEntry' => $controlEntry,
                'variantEntry' => $variantEntry,
            ]);
        }

        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        if ($test->isTrashed()) {
            return $this->redirectToTrash();
        }

        // Get the entry objects for the element select fields
        $controlEntry = $test->controlEntryId
            ? Entry::find()->id($test->controlEntryId)->siteId($test->siteId)->status(null)->one()
            : null;
        $variantEntry = $test->variantEntryId
            ? Entry::find()->id($test->variantEntryId)->siteId($test->siteId)->status(null)->one()
            : null;

        return $this->renderTemplate('abtestcraft/tests/_form', [
            'test' => $test,
            'isNew' => false,
            'controlEntry' => $controlEntry,
            'variantEntry' => $variantEntry,
        ]);
    }

    /**
     * View test results
     */
    public function actionResults(int $testId): Response
    {
        $this->requirePermission('abtestcraft:viewResults');

        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        if ($test->isTrashed()) {
            return $this->redirectToTrash();
        }

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);
        $timeEstimate = ABTestCraft::getInstance()->stats->getTimeEstimate($test);

        return $this->renderTemplate('abtestcraft/tests/results', [
            'test' => $test,
            'stats' => $stats,
            'timeEstimate' => $timeEstimate,
        ]);
    }

    /**
     * Save test
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $request = Craft::$app->getRequest();
        $testId = $this->getTestIdParam(false);

        if ($testId) {
            $test = $this->getPostedTest();
        } else {
            // Same license gate as the New Test page; a direct POST mustn't bypass it
            if (!ABTestCraft::getInstance()->license->canCreateTests()) {
                throw new ForbiddenHttpException(
                    Craft::t('abtestcraft', 'A valid license is required to create new tests.')
                );
            }

            $test = new Test();
            // Only new tests take the current CP site. Re-saving an existing
            // test with the site switcher on another site mustn't move it.
            $test->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        $test->name = $request->getBodyParam('name');
        $test->handle = $request->getBodyParam('handle');

        // Auto-generate handle from name if empty or still matches a previous auto-generation
        $test->generateHandle();

        $test->hypothesis = $request->getBodyParam('hypothesis');
        $test->variantDescription = $request->getBodyParam('variantDescription');

        // Element select returns an array of IDs, get the first one and cast to int
        $controlEntryIds = $request->getBodyParam('controlEntryId');
        $variantEntryIds = $request->getBodyParam('variantEntryId');
        $controlId = is_array($controlEntryIds) ? ($controlEntryIds[0] ?? null) : $controlEntryIds;
        $variantId = is_array($variantEntryIds) ? ($variantEntryIds[0] ?? null) : $variantEntryIds;
        $test->controlEntryId = $controlId ? (int) $controlId : null;
        $test->variantEntryId = $variantId ? (int) $variantId : null;

        $test->trafficSplit = (int) $request->getBodyParam('trafficSplit', 50);

        // Keep legacy fields for backward compatibility
        $test->goalType = $request->getBodyParam('goalType');
        $test->goalValue = $request->getBodyParam('goalValue');

        if (!ABTestCraft::getInstance()->tests->saveTest($test)) {
            $errors = $test->getErrors();
            $errorMessages = [];
            foreach ($errors as $attribute => $messages) {
                $errorMessages[] = $attribute . ': ' . implode(', ', $messages);
            }
            Craft::$app->getSession()->setError('Could not save test. ' . implode(' | ', $errorMessages));

            // Get entry objects for re-displaying the form
            $controlEntry = $test->controlEntryId
                ? Entry::find()->id($test->controlEntryId)->siteId($test->siteId)->status(null)->one()
                : null;
            $variantEntry = $test->variantEntryId
                ? Entry::find()->id($test->variantEntryId)->siteId($test->siteId)->status(null)->one()
                : null;

            Craft::$app->getUrlManager()->setRouteParams([
                'test' => $test,
                'controlEntry' => $controlEntry,
                'variantEntry' => $variantEntry,
            ]);

            return null;
        }

        // Save goals (new multi-goal system)
        $goalsData = $request->getBodyParam('goals', []);
        $this->saveGoalsFromRequest($test, $goalsData);

        $this->clearTestCounts($test);
        Craft::$app->getSession()->setNotice('Test saved.');

        return $this->redirectToPostedUrl($test);
    }

    /**
     * Save learnings for a test (from results page)
     */
    public function actionSaveLearnings(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $test = $this->getPostedTest();
        $test->learnings = Craft::$app->getRequest()->getBodyParam('learnings');

        if (!ABTestCraft::getInstance()->tests->saveTest($test)) {
            Craft::$app->getSession()->setError('Could not save learnings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Learnings saved.');

        return $this->redirectToPostedUrl($test);
    }

    /**
     * Read the posted test ID, accepting only a positive whole number.
     *
     * A plain (int) cast would turn junk into a real ID, e.g. an array
     * (`testId[]=7`) becomes 1 and would act on test #1.
     *
     * @throws BadRequestHttpException
     */
    private function getTestIdParam(bool $required = true): ?int
    {
        $value = $this->request->getBodyParam('testId');

        if (!$required && ($value === null || $value === '')) {
            return null;
        }

        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value) || (int) $value < 1) {
            throw new BadRequestHttpException('Invalid test ID.');
        }

        return (int) $value;
    }

    /**
     * Load the test named by the posted testId.
     *
     * Tests in the trash must be restored before they can be saved, started,
     * paused, completed or trashed again, so they're rejected unless
     * $allowTrashed is set.
     *
     * @throws BadRequestHttpException|NotFoundHttpException
     */
    private function getPostedTest(bool $allowTrashed = false): Test
    {
        $test = ABTestCraft::getInstance()->tests->getTestById($this->getTestIdParam());

        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        if ($test->isTrashed() && !$allowTrashed) {
            throw new BadRequestHttpException(
                Craft::t('abtestcraft', 'This test is in the trash. Restore it first.')
            );
        }

        return $test;
    }

    /**
     * Send the user to the Trash tab when they open a trashed test's pages.
     */
    private function redirectToTrash(): Response
    {
        Craft::$app->getSession()->setError(
            Craft::t('abtestcraft', 'This test is in the trash. Restore it to view or edit it.')
        );

        return $this->redirect(UrlHelper::cpUrl('abtestcraft/tests', ['status' => 'trashed']));
    }

    /**
     * Cache key for the tab counts on the Tests page.
     */
    private static function countsCacheKey(int|string|null $siteId): string
    {
        return "abtestcraft_counts_{$siteId}";
    }

    /**
     * Clear the cached tab counts after a test moves between tabs.
     */
    private function clearTestCounts(Test $test): void
    {
        Craft::$app->getCache()->delete(self::countsCacheKey($test->siteId));
    }

    /**
     * The unsaved test and entries actionSave() hands back after a failed save.
     *
     * Read from Craft's route params rather than action arguments: Yii also
     * binds query-string values to action arguments, so `?test=1` would pass
     * a string into a Test-typed parameter and throw a TypeError.
     *
     * @return array{0: ?Test, 1: ?Entry, 2: ?Entry}
     */
    private function getFailedSaveParams(): array
    {
        $params = Craft::$app->getUrlManager()->getRouteParams() ?? [];

        $test = $params['test'] ?? null;
        $controlEntry = $params['controlEntry'] ?? null;
        $variantEntry = $params['variantEntry'] ?? null;

        return [
            $test instanceof Test ? $test : null,
            $controlEntry instanceof Entry ? $controlEntry : null,
            $variantEntry instanceof Entry ? $variantEntry : null,
        ];
    }

    /**
     * Process and save goals from form data
     */
    private function saveGoalsFromRequest(Test $test, array $goalsData): void
    {
        $goalsToSave = [];

        foreach ($goalsData as $goalType => $goalData) {
            // Check if this goal is enabled (lightswitch returns '1' when on)
            $isEnabled = !empty($goalData['isEnabled']);

            if (!$isEnabled) {
                continue;
            }

            $config = $goalData['config'] ?? [];

            // Process config based on goal type
            if ($goalType === 'form') {
                $config = $this->processFormGoalConfig($config);
            } elseif ($goalType === 'download' && isset($config['extensions'])) {
                // Convert comma-separated string to array
                $extensions = $config['extensions'];
                if (is_string($extensions)) {
                    $config['extensions'] = array_map('trim', explode(',', $extensions));
                }
            }

            $goalsToSave[] = [
                'goalType' => $goalType,
                'isEnabled' => true,
                'config' => $config,
            ];
        }

        ABTestCraft::getInstance()->goals->saveGoalsForTest($test, $goalsToSave);
    }

    /**
     * Process form goal configuration
     * Handles both smart mode (multi-select forms) and advanced mode (CSS selectors)
     */
    private function processFormGoalConfig(array $config): array
    {
        $mode = $config['mode'] ?? 'advanced';

        if ($mode === 'smart') {
            // Get array of selected forms (checkboxes)
            // Forms are submitted as 'plugin:handle' strings
            $forms = $config['forms'] ?? [];

            // Ensure forms is an array (could be empty)
            if (!is_array($forms)) {
                $forms = [];
            }

            // Filter out empty values
            $forms = array_filter($forms, fn($f) => !empty($f));
            $config['forms'] = array_values($forms);

            // Clear legacy single-form fields
            unset($config['formId']);
            unset($config['pluginHandle']);
            unset($config['formHandle']);

            // Clear advanced mode fields when in smart mode
            unset($config['formSelector']);
            unset($config['successMethod']);
            unset($config['successSelector']);
        } else {
            // Advanced mode - clear smart mode fields
            unset($config['formId']);
            unset($config['forms']);
            unset($config['pluginHandle']);
            unset($config['formHandle']);
        }

        return $config;
    }

    /**
     * Move a test to the trash (soft delete). A trashed test stops running.
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $test = $this->getPostedTest();

        if (!ABTestCraft::getInstance()->tests->deleteTest($test)) {
            return $this->asFailure(Craft::t('abtestcraft', 'Could not delete test.'));
        }

        $this->clearTestCounts($test);

        // Also flashed for JSON requests: the page redirects to the Tests list
        // and shows it there
        $message = Craft::t('abtestcraft', 'Test moved to the trash.');
        Craft::$app->getSession()->setNotice($message);

        return $this->asSuccess($message, ['success' => true], UrlHelper::cpUrl('abtestcraft/tests'));
    }

    /**
     * Restore a test from the trash. A test that was running comes back paused.
     */
    public function actionRestore(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $test = $this->getPostedTest(true);
        // The stored status, not isRunning(): that's always false in the trash
        $wasRunning = $test->status === Test::STATUS_RUNNING;

        if (!ABTestCraft::getInstance()->tests->restoreTest($test)) {
            return $this->asFailure(Craft::t('abtestcraft', 'Could not restore test.'));
        }

        $this->clearTestCounts($test);

        return $this->asSuccess($wasRunning
            ? Craft::t('abtestcraft', 'Test restored and paused. Start it again when you’re ready.')
            : Craft::t('abtestcraft', 'Test restored.'));
    }

    /**
     * Permanently delete a test from the trash, with all its results.
     *
     * Only trashed tests can be permanently deleted, so a test always passes
     * through the trash first.
     */
    public function actionDeletePermanently(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $test = $this->getPostedTest(true);

        if (!$test->isTrashed()) {
            throw new BadRequestHttpException(
                Craft::t('abtestcraft', 'Only tests in the trash can be permanently deleted.')
            );
        }

        if (!ABTestCraft::getInstance()->tests->hardDeleteTest($test)) {
            return $this->asFailure(Craft::t('abtestcraft', 'Could not delete test.'));
        }

        $this->clearTestCounts($test);

        return $this->asSuccess(Craft::t('abtestcraft', 'Test permanently deleted.'));
    }

    /**
     * Start test
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $test = $this->getPostedTest();

        $result = ABTestCraft::getInstance()->tests->startTest($test);

        if ($result !== true) {
            // $result is an error message string
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asFailure($result);
            }
            Craft::$app->getSession()->setError($result);
            return $this->redirectToPostedUrl($test);
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asSuccess('Test started.');
        }

        Craft::$app->getSession()->setNotice('Test started.');
        return $this->redirectToPostedUrl($test);
    }

    /**
     * Pause test
     */
    public function actionPause(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $test = $this->getPostedTest();

        if (!ABTestCraft::getInstance()->tests->pauseTest($test)) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asFailure('Could not pause test.');
            }
            Craft::$app->getSession()->setError('Could not pause test.');
            return $this->redirectToPostedUrl($test);
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asSuccess('Test paused.');
        }

        Craft::$app->getSession()->setNotice('Test paused.');
        return $this->redirectToPostedUrl($test);
    }

    /**
     * Complete test and declare winner
     */
    public function actionComplete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('abtestcraft:manageTests');

        $test = $this->getPostedTest();
        $winner = Craft::$app->getRequest()->getBodyParam('winner');

        if (!ABTestCraft::getInstance()->tests->completeTest($test, $winner)) {
            Craft::$app->getSession()->setError('Could not complete test.');
            return $this->redirectToPostedUrl($test);
        }

        $this->clearTestCounts($test);
        Craft::$app->getSession()->setNotice('Test completed.');
        return $this->redirectToPostedUrl($test);
    }
}
