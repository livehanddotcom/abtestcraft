<?php

declare(strict_types=1);

namespace livehand\abtestcraft;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Model;
use craft\console\Request as ConsoleRequest;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\services\Elements;
use craft\services\Structures;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use livehand\abtestcraft\console\controllers\TestsController as ConsoleTestsController;
use livehand\abtestcraft\models\Settings;
use craft\elements\Entry;
use craft\web\twig\variables\CraftVariable;
use livehand\abtestcraft\services\AssignmentService;
use livehand\abtestcraft\services\AuditService;
use livehand\abtestcraft\services\CascadeService;
use livehand\abtestcraft\services\FormPluginDetectorService;
use livehand\abtestcraft\services\FormSubmissionService;
use livehand\abtestcraft\services\GoalsService;
use livehand\abtestcraft\services\NotificationService;
use livehand\abtestcraft\services\PageCacheService;
use livehand\abtestcraft\services\PageVisitService;
use livehand\abtestcraft\services\VisitorFilterService;
use livehand\abtestcraft\services\ConsentService;
use livehand\abtestcraft\services\TestRulesService;
use livehand\abtestcraft\services\StateService;
use livehand\abtestcraft\services\RoutingService;
use livehand\abtestcraft\services\SeoService;
use livehand\abtestcraft\services\StatsService;
use livehand\abtestcraft\services\TestsService;
use livehand\abtestcraft\services\TrackingService;
use livehand\abtestcraft\services\LicenseService;
use livehand\abtestcraft\variables\ABTestCraftVariable;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\events\RegisterCpAlertsEvent;
use yii\base\Event;

/**
 * ABTestCraft plugin for CraftCMS 5
 * A/B testing for CraftCMS - test page variations and track conversions
 *
 * @author Livehand Inc.
 * @property Settings $settings
 * @property TestsService $tests
 * @property GoalsService $goals
 * @property AssignmentService $assignment
 * @property RoutingService $routing
 * @property TrackingService $tracking
 * @property StatsService $stats
 * @property SeoService $seo
 * @property CascadeService $cascade
 * @property FormPluginDetectorService $formPluginDetector
 * @property FormSubmissionService $formSubmissions
 * @property NotificationService $notifications
 * @property AuditService $audit
 * @property LicenseService $license
 * @property PageCacheService $pageCache
 * @property PageVisitService $pageVisits
 * @property VisitorFilterService $visitorFilter
 * @property ConsentService $consent
 * @property TestRulesService $rules
 * @property StateService $state
 */
class ABTestCraft extends BasePlugin
{
    public string $schemaVersion = '1.6.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'tests' => TestsService::class,
                'goals' => GoalsService::class,
                'assignment' => AssignmentService::class,
                'routing' => RoutingService::class,
                'tracking' => TrackingService::class,
                'stats' => StatsService::class,
                'seo' => SeoService::class,
                'cascade' => CascadeService::class,
                'formPluginDetector' => FormPluginDetectorService::class,
                'formSubmissions' => FormSubmissionService::class,
                'notifications' => NotificationService::class,
                'audit' => AuditService::class,
                'license' => LicenseService::class,
                'pageCache' => PageCacheService::class,
                'pageVisits' => PageVisitService::class,
                'visitorFilter' => VisitorFilterService::class,
                'consent' => ConsentService::class,
                'rules' => TestRulesService::class,
                'state' => StateService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register console commands
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'livehand\\abtestcraft\\console\\controllers';
            // Commands are abtestcraft/tests/<command>. Before 1.0.2 the console
            // controller was ABTestCraftController, so keep its route working,
            // registered only when used so `./craft help` lists each command once.
            $request = Craft::$app->getRequest();
            $route = $request instanceof ConsoleRequest ? ($request->getParams()[0] ?? '') : '';
            if (is_string($route) && str_starts_with($route, 'abtestcraft/a-b-test-craft')) {
                $this->controllerMap['a-b-test-craft'] = ConsoleTestsController::class;
            }
        }

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Consent settings change the script on every page: refresh Blitz's
     * cache so cached pages get it
     */
    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        $this->pageCache->clearAllIfPageScriptChanged();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('abtestcraft/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'licenseInfo' => $this->license->getStatusInfo(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'ABTestCraft';
        $item['subnav'] = [
            'tests' => ['label' => 'Tests', 'url' => 'abtestcraft/tests'],
            'settings' => ['label' => 'Settings', 'url' => 'settings/plugins/abtestcraft'],
        ];
        return $item;
    }

    private function attachEventHandlers(): void
    {
        // Register license alerts in CP
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(
                Cp::class,
                Cp::EVENT_REGISTER_ALERTS,
                function (RegisterCpAlertsEvent $event) {
                    $this->registerLicenseAlert($event);
                    $this->registerEmailAlert($event);
                }
            );
        }

        // Register user permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'ABTestCraft',
                    'permissions' => [
                        'abtestcraft:manageTests' => [
                            'label' => 'Manage split tests',
                        ],
                        'abtestcraft:viewResults' => [
                            'label' => 'View test results',
                        ],
                    ],
                ];
            }
        );

        // Register Twig variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                $event->sender->set('abtestcraft', ABTestCraftVariable::class);
            }
        );

        // Register CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['abtestcraft'] = 'abtestcraft/tests/index';
                $event->rules['abtestcraft/tests'] = 'abtestcraft/tests/index';
                $event->rules['abtestcraft/tests/new'] = 'abtestcraft/tests/new';
                $event->rules['abtestcraft/tests/<testId:\d+>'] = 'abtestcraft/tests/edit';
                $event->rules['abtestcraft/tests/<testId:\d+>/results'] = 'abtestcraft/tests/results';
            }
        );

        // Only register front-end handlers for site requests
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            // Handle template swapping before render
            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
                function (TemplateEvent $event) {
                    $this->routing->handleBeforeRender($event);

                    // Inject SEO protection for variant entries
                    $element = Craft::$app->getUrlManager()->getMatchedElement();
                    if ($element instanceof Entry) {
                        $this->seo->injectSeoProtection($element);
                    }
                }
            );

            // Inject tracking script after render
            Event::on(
                View::class,
                View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
                function (TemplateEvent $event) {
                    $this->injectTrackingScript($event);
                }
            );
        }

        // Scheduled starts/ends, guardrails, auto-stop and the weekly summary:
        // checked at most once a minute after web requests (or from cron with
        // `./craft abtestcraft/tests/run-scheduled`)
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->on(\yii\base\Application::EVENT_AFTER_REQUEST, function () {
                $this->rules->maybeTick();
            });
        }

        // Count smart-mode form goals when Freeform/Formie accept a submission
        $this->formSubmissions->registerEventHandlers();

        // Register structure change handlers for cascade synchronization
        $this->registerCascadeEventHandlers();
    }

    /**
     * Register event handlers for keeping cascade mappings in sync with entry structure changes
     */
    private function registerCascadeEventHandlers(): void
    {
        // Handle entries being moved in structure
        Event::on(
            Structures::class,
            Structures::EVENT_AFTER_MOVE_ELEMENT,
            function (MoveElementEvent $event) {
                if ($event->element instanceof Entry) {
                    $this->cascade->handleEntryMoved($event->element);
                }
            }
        );

        // Handle entries being inserted into structure
        Event::on(
            Structures::class,
            Structures::EVENT_AFTER_INSERT_ELEMENT,
            function (MoveElementEvent $event) {
                if ($event->element instanceof Entry) {
                    $this->cascade->handleEntryMoved($event->element);
                }
            }
        );

        // Handle entry deletions
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (ElementEvent $event) {
                if ($event->element instanceof Entry) {
                    $this->cascade->handleEntryDeleted($event->element->id);
                }
            }
        );
    }

    /**
     * Inject the page-visit beacon into every front-end page, and the full
     * tracking script into pages with a running test
     */
    private function injectTrackingScript(TemplateEvent $event): void
    {
        // Editors previewing entries aren't visitors
        if (Craft::$app->getRequest()->getIsPreview()) {
            return;
        }

        // Consent mode script (empty unless consent is required) and the
        // page-visit beacon: both identical for every visitor, so cache-safe
        $script = $this->consent->getConsentScript() . $this->pageVisits->getBeaconScript() . "\n";

        // An editor previewing a variant (?abtestcraft=variant) isn't tracked
        if ($this->routing->hasActiveTest() && !$this->routing->isEditorPreview()) {
            $script .= $this->getTrackingScript() ?? '';
        }

        // Inject before </body> - use strrpos to only replace the last occurrence
        $pos = strrpos($event->output, '</body>');
        if ($pos !== false) {
            $event->output = substr_replace($event->output, $script . '</body>', $pos, 7);
        }
    }

    /**
     * The tracking script and its configuration for the page's running test
     */
    private function getTrackingScript(): ?string
    {
        $test = $this->routing->getActiveTest();
        if ($test === null) {
            return null;
        }
        $variant = $this->routing->getActiveVariant();
        $settings = $this->getSettings();

        // Get goals configuration for JavaScript
        $goalsConfig = $test->getGoalsJsConfig();

        // Build configuration for JavaScript
        $config = [
            'testHandle' => $test->handle,
            'variant' => $variant,
            // No CSRF token: a token baked into the page goes stale under any
            // page cache, and the endpoint doesn't need one (see TrackController)
            'trackingEndpoint' => '/actions/abtestcraft/track/convert',
            'trackPhoneClicks' => $settings->trackPhoneClicks,
            'trackEmailClicks' => $settings->trackEmailClicks,
            'trackFormSubmissions' => $settings->trackFormSubmissions,
            'trackFileDownloads' => $settings->trackFileDownloads,
            'enableDataLayer' => $settings->enableDataLayer,
            // New multi-goal configuration
            'goals' => $goalsConfig,
            // Keep legacy fields for backward compatibility
            'goalType' => $test->goalType,
            'goalValue' => $test->goalValue,
        ];

        // Use safe JSON encoding to prevent XSS
        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS);

        // Get tracking script content with proper error handling
        $trackingJsPath = __DIR__ . '/web/assets/tracking/tracking.js';
        try {
            if (!file_exists($trackingJsPath)) {
                throw new \RuntimeException("tracking.js not found at: {$trackingJsPath}");
            }
            $trackingJs = file_get_contents($trackingJsPath);
            if ($trackingJs === false) {
                throw new \RuntimeException("Failed to read tracking.js");
            }
        } catch (\Throwable $e) {
            Craft::error('ABTestCraft tracking script error: ' . $e->getMessage(), __METHOD__);
            return null;
        }

        return "<script>window.ABTestCraftConfig = {$configJson};</script>\n"
            . "<script>{$trackingJs}</script>\n";
    }

    /**
     * Register license alert in the control panel
     */
    private function registerLicenseAlert(RegisterCpAlertsEvent $event): void
    {
        // Only nag on the plugin's own pages, not across the whole CP
        if (!$this->isPluginCpPage()) {
            return;
        }

        $licenseInfo = $this->license->getStatusInfo();

        // Only show alert for trial or issue states
        if ($licenseInfo['message'] === null) {
            return;
        }

        $message = $licenseInfo['message'];

        // Add link to Plugin Store for trial
        if ($this->license->isTrial()) {
            $buyUrl = UrlHelper::url('plugin-store/abtestcraft');
            $message .= ' <a class="go" href="' . $buyUrl . '">' .
                Craft::t('abtestcraft', 'Buy now') . '</a>';
        } elseif ($this->license->hasIssues()) {
            $message .= ' <a class="go" href="https://console.craftcms.com" target="_blank" rel="noopener">' .
                Craft::t('abtestcraft', 'Manage license') . '</a>';
        }

        $event->alerts[] = $message;
    }

    /**
     * Warn when emails are switched on but there's no address to send them
     * to (guardrail and schedule emails use the same addresses)
     */
    private function registerEmailAlert(RegisterCpAlertsEvent $event): void
    {
        if (!$this->isPluginCpPage() || $this->notifications->hasRecipients()) {
            return;
        }

        $settings = $this->getSettings();
        if (!$settings->sendSignificanceEmail && !$settings->sendWeeklySummary) {
            return;
        }

        $event->alerts[] = Craft::t(
            'abtestcraft',
            'ABTestCraft emails are switched on, but no notification address is set, so none are being sent '
            . '(including safety guardrail and schedule emails).'
        ) . ' <a class="go" href="' . UrlHelper::cpUrl('settings/plugins/abtestcraft') . '">'
            . Craft::t('abtestcraft', 'Add an address') . '</a>';
    }

    /**
     * Whether the CP page being viewed belongs to this plugin (abtestcraft/*).
     *
     * Craft builds alerts either while rendering the page, or via an
     * `app/get-cp-alerts` action request that posts the page's `path`, so
     * the path has to come from whichever of the two this request is.
     */
    private function isPluginCpPage(): bool
    {
        $request = Craft::$app->getRequest();

        // Only Craft's background alerts fetch carries the page path in the
        // body; other action requests (e.g. a form re-rendered after a failed
        // save) are on the page they were posted to
        $isAlertsFetch = $request->getIsActionRequest()
            && $request->getActionSegments() === ['app', 'get-cp-alerts'];

        $path = $isAlertsFetch
            ? $request->getBodyParam('path')
            : $request->getPathInfo();

        if (!is_string($path)) {
            return false;
        }

        $path = trim($path, '/');

        return $path === $this->handle || str_starts_with($path, $this->handle . '/');
    }
}
