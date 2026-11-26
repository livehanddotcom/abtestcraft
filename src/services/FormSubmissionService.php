<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use yii\base\Event;

/**
 * Counts smart-mode (Easy mode) form goals on the server.
 *
 * Listens for Freeform's and Formie's own "submission succeeded" events. The
 * submission request carries the visitor's first-party cookie, so the
 * conversion is recorded there for every running test the visitor is assigned
 * to. Compared with detecting success in the browser, this:
 *
 * - works for AJAX and page-reload forms, with or without a redirect to a
 *   thank-you page (the redirect target doesn't matter: the submission itself
 *   is what's counted)
 * - uses the form plugin's own spam verdict
 * - isn't affected by full-page caching: submissions always reach PHP
 *
 * The browser no longer counts smart-mode form goals (TrackingService refuses
 * them), so a submission is never counted twice. Advanced mode (CSS selectors)
 * is still detected in the browser.
 */
class FormSubmissionService extends Component
{
    public const PLUGIN_FREEFORM = 'freeform';
    public const PLUGIN_FORMIE = 'formie';

    // Referenced by name so neither form plugin is a dependency
    private const FREEFORM_FORM = 'Solspace\Freeform\Form\Form';
    private const FORMIE_SUBMISSIONS = 'verbb\formie\services\Submissions';
    private const FORMIE_SUBMISSION = 'verbb\formie\elements\Submission';

    /**
     * Listen for successful submissions of each installed form plugin.
     */
    public function registerEventHandlers(): void
    {
        // Freeform 5: fires once a submission is finished and valid (not on
        // intermediate pages of multi-page forms). Freeform's own spam checks
        // have run by then; async (queued) spam checks finish later.
        if (class_exists(self::FREEFORM_FORM)) {
            Event::on(self::FREEFORM_FORM, 'after-submit', function (Event $event) {
                $form = method_exists($event, 'getForm') ? $event->getForm() : null;
                if (!is_object($form) || !method_exists($form, 'getHandle')) {
                    return;
                }
                if (method_exists($form, 'isMarkedAsSpam') && $form->isMarkedAsSpam()) {
                    return;
                }
                $this->handleSubmissionEvent(self::PLUGIN_FREEFORM, (string) $form->getHandle());
            });
        }

        // Formie 4: Submission::EVENT_AFTER_COMPLETE fires when a submission
        // becomes complete (not on page steps). Discarded spam fires nothing;
        // spam that's kept is flagged isSpam.
        if (defined(self::FORMIE_SUBMISSION . '::EVENT_AFTER_COMPLETE')) {
            Event::on(self::FORMIE_SUBMISSION, 'afterComplete', function (Event $event) {
                $submission = $event->submission ?? null;
                $form = $event->form ?? null;
                if (!is_object($submission) || !is_object($form) || !empty($submission->isSpam)) {
                    return;
                }
                $this->handleSubmissionEvent(self::PLUGIN_FORMIE, (string) $form->handle);
            });
        } elseif (defined(self::FORMIE_SUBMISSIONS . '::EVENT_AFTER_SUBMISSION')) {
            // Formie 3: afterSubmission fires for complete submissions with
            // success = false for spam or failed payments; incomplete
            // (multi-page, saved) submissions fire a different event.
            Event::on(self::FORMIE_SUBMISSIONS, 'afterSubmission', function (Event $event) {
                $submission = $event->submission ?? null;
                if (($event->success ?? false) !== true || !is_object($submission)) {
                    return;
                }
                if (!empty($submission->isIncomplete) || !empty($submission->isSpam)) {
                    return;
                }
                $form = method_exists($submission, 'getForm') ? $submission->getForm() : null;
                if (!is_object($form)) {
                    return;
                }
                $this->handleSubmissionEvent(self::PLUGIN_FORMIE, (string) $form->handle);
            });
        }
    }

    /**
     * Record a successful form submission for the current visitor's tests.
     *
     * A test counts it when it's running, the visitor is assigned to it, and
     * its form goal is enabled, in smart mode and covers the form. A goal with
     * specific forms selected counts them wherever they're submitted; a goal
     * with none selected counts any form, but only when submitted on one of
     * the test's pages (given in $pageUrls).
     *
     * @param string[] $pageUrls URLs the form may have been submitted from
     *                           (the referring page and the request URL)
     * @return int The number of tests that counted the conversion
     */
    public function recordFormSubmission(string $pluginHandle, string $formHandle, array $pageUrls = []): int
    {
        if ($formHandle === '' || !ABTestCraft::getInstance()->getSettings()->trackFormSubmissions) {
            return 0;
        }

        $visitorId = ABTestCraft::getInstance()->assignment->getVisitorId();
        if ($visitorId === null) {
            return 0; // never assigned to a test
        }

        $testIds = (new Query())
            ->select(['testId'])
            ->from('{{%abtestcraft_visitors}}')
            ->where(['visitorId' => $visitorId])
            ->column();

        $counted = 0;
        foreach ($testIds as $testId) {
            $test = ABTestCraft::getInstance()->tests->getTestById((int) $testId);
            if (!$test || !$test->isRunning()) {
                continue;
            }

            $goal = ABTestCraft::getInstance()->goals->getGoalByTestAndType((int) $test->id, Goal::TYPE_FORM);
            if (!$goal || !$goal->isEnabled || !$goal->isSmartMode()) {
                continue;
            }

            if (!$goal->coversForm($pluginHandle, $formHandle)) {
                continue;
            }

            if (!$goal->hasSelectedForms() && !$this->isAnyTestedPage($test, $pageUrls)) {
                continue;
            }

            if (ABTestCraft::getInstance()->tracking->recordConversion($test, Goal::TYPE_FORM, $goal->id)) {
                $counted++;
            }
        }

        return $counted;
    }

    /**
     * Whether a URL is one of a test's pages: the control entry, one of its
     * child pages, or the variant entry's own URL.
     */
    public function isTestedPageUrl(Test $test, string $url): bool
    {
        $site = Craft::$app->getSites()->getSiteById((int) $test->siteId);
        $path = $this->getRequestPath($url);
        if (!$site || $path === null) {
            return false;
        }

        // Make the path relative to the site's base URL (e.g. strip "/fr")
        $uri = trim($path, '/');
        $basePath = trim((string) parse_url((string) $site->getBaseUrl(), PHP_URL_PATH), '/');
        if ($basePath !== '') {
            if ($uri !== $basePath && !str_starts_with($uri, $basePath . '/')) {
                return false;
            }
            $uri = ltrim(substr($uri, strlen($basePath)), '/');
        }

        $element = Craft::$app->getElements()->getElementByUri($uri === '' ? '__home__' : $uri, $site->id, true);
        if (!$element instanceof Entry) {
            return false;
        }

        $entryId = (int) $element->id;
        if ($entryId === $test->controlEntryId || $entryId === $test->variantEntryId) {
            return true;
        }

        return (new Query())
            ->from('{{%abtestcraft_test_descendants}}')
            ->where(['testId' => $test->id, 'descendantEntryId' => $entryId, 'siteId' => $site->id])
            ->exists();
    }

    /**
     * Handle a form plugin's success event: front-end requests only, so
     * control panel actions (e.g. Formie's "mark complete") and console
     * commands never count, and a failure here never breaks the submission.
     */
    private function handleSubmissionEvent(string $pluginHandle, string $formHandle): void
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest()) {
            return;
        }

        // Bots and control panel users aren't counted (see VisitorFilterService)
        if (ABTestCraft::getInstance()->visitorFilter->shouldExcludeRequest()) {
            return;
        }

        try {
            $pageUrls = array_values(array_filter([
                $request->getReferrer(),
                $request->getAbsoluteUrl(),
            ]));
            $this->recordFormSubmission($pluginHandle, $formHandle, $pageUrls);
        } catch (\Throwable $e) {
            Craft::error(
                "Failed to record {$pluginHandle} form submission ({$formHandle}): " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * The Craft path a URL requests, including on sites without URL rewriting:
     * "/index.php/about" and "/index.php?p=about" both request "about".
     */
    private function getRequestPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        if (preg_match('#(^|/)index\.php(/.*)?$#', $path, $matches)) {
            if (!empty($matches[2])) {
                return substr($path, 0, -strlen($matches[2]) - strlen('index.php')) . ltrim($matches[2], '/');
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $pathParam = Craft::$app->getConfig()->getGeneral()->pathParam ?? 'p';
            $value = $query[$pathParam] ?? '';

            return substr($path, 0, -strlen('index.php')) . (is_string($value) ? $value : '');
        }

        return $path;
    }

    /**
     * @param string[] $urls
     */
    private function isAnyTestedPage(Test $test, array $urls): bool
    {
        foreach ($urls as $url) {
            if ($this->isTestedPageUrl($test, $url)) {
                return true;
            }
        }

        return false;
    }
}
