<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\web\Response;
use livehand\abtestcraft\models\Test;
use yii\base\Event;

/**
 * Page cache service - keeps full-page caches from breaking split tests
 *
 * Variant assignment happens server-side during render, so a page served from
 * a full-page cache would show every visitor whichever variant was cached
 * first and would never record assignments or impressions. This service:
 *
 * - Marks responses for pages with a running test as private, so CDNs and
 *   reverse proxies don't share one visitor's variant with everyone.
 * - When Blitz is installed, tells Blitz not to cache those pages and clears
 *   any existing Blitz cache for them when a test is saved while running.
 *
 * Blitz is an optional dependency, so all Blitz access is guarded and
 * referenced by class-string to avoid a hard requirement.
 */
class PageCacheService extends Component
{
    private const BLITZ_CLASS = 'putyourlightson\\blitz\\Blitz';
    private const BLITZ_SITE_URI_CLASS = 'putyourlightson\\blitz\\models\\SiteUriModel';
    private const BLITZ_CACHES_TABLE = '{{%blitz_caches}}';

    private bool $enforcingPrivateResponse = false;

    /**
     * Prevent the current response from being stored by any shared cache.
     * Called during render when the requested page has a running test.
     */
    public function excludeCurrentRequest(): void
    {
        // `private` stops CDNs/proxies from storing the response; `no-cache`
        // makes the browser revalidate so a stopped test takes effect promptly
        Craft::$app->getResponse()->getHeaders()->set('Cache-Control', 'private, no-cache');

        // Templates (`{% header %}`) or other plugins can still replace that
        // header later in the request, e.g. with `public, max-age=3600`, which
        // would let a CDN serve one visitor's variant to everyone. So enforce
        // it again once the response is final.
        if (!$this->enforcingPrivateResponse) {
            $this->enforcingPrivateResponse = true;
            Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function (Event $event) {
                if ($event->sender instanceof Response) {
                    self::makeResponsePrivate($event->sender);
                }
            });
        }

        $blitz = $this->getBlitz();
        if ($blitz === null) {
            return;
        }

        // Blitz saves the page after render only if this is still true
        // (CacheOptionsModel, available on generateCache since Blitz 3)
        $blitz->generateCache->options->cachingEnabled = false;
    }

    /**
     * Ensure a response can't be stored by shared caches. Keeps an already
     * unshareable Cache-Control (`no-store` or `private`, e.g. Craft's own
     * no-cache headers); replaces anything else with `private, no-cache`.
     */
    public static function makeResponsePrivate(Response $response): void
    {
        $headers = $response->getHeaders();
        $current = strtolower((string) $headers->get('Cache-Control', ''));

        if (str_contains($current, 'no-store') || str_contains($current, 'private')) {
            return;
        }

        $headers->set('Cache-Control', 'private, no-cache');
    }

    /**
     * Whether the current request is Blitz's own cache generator crawling the
     * site. These requests must not be assigned a variant or counted as an
     * impression, since they aren't real visitors.
     */
    public function isCacheGeneratorRequest(): bool
    {
        $blitz = $this->getBlitz();
        if ($blitz === null) {
            return false;
        }

        try {
            return $blitz->cacheRequest->getIsGeneratorRequest();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Clear Blitz's cache for a test's control page and its descendants
     * (cascaded pages), so visitors stop getting a stale cached copy and the
     * test can assign variants. Safe to call when Blitz isn't installed.
     */
    public function clearTestPages(Test $test): void
    {
        $blitz = $this->getBlitz();
        if ($blitz === null || !$test->controlEntryId) {
            return;
        }

        try {
            $siteUris = $this->getTestSiteUris($test);
            if (empty($siteUris)) {
                return;
            }

            // Remove cached files/records, then purge any reverse proxy (e.g. Cloudflare)
            $blitz->clearCache->clearUris($siteUris);
            $blitz->cachePurger->purgeUris($siteUris);

            Craft::info(
                "ABTestCraft: Cleared Blitz cache for " . count($siteUris) . " URI(s) of test '{$test->handle}'",
                __METHOD__
            );
        } catch (\Throwable $e) {
            Craft::warning(
                "ABTestCraft: Could not clear Blitz cache for test '{$test->handle}': " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Build Blitz SiteUriModels for every URL a test affects (control, its
     * descendants and the variant), plus the versions of them Blitz caches
     * separately: query strings and path-based pagination.
     *
     * @return object[] SiteUriModel instances
     */
    private function getTestSiteUris(Test $test): array
    {
        $siteId = (int) $test->siteId;

        $controlEntry = Entry::find()
            ->id($test->controlEntryId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if (!$controlEntry) {
            return [];
        }

        // Query descendants directly rather than the cascade table, which may
        // still be rebuilding in a queue job when this runs. Only the URI is
        // needed, so skip hydrating entries (trees can be large).
        $descendantUris = Entry::find()
            ->descendantOf($controlEntry)
            ->siteId($siteId)
            ->status(null)
            ->select(['elements_sites.uri'])
            ->column();

        // The variant's own URL too: while the test runs it carries noindex
        // and a canonical to the control, so any copy cached before the test
        // started is wrong (see SeoService::injectSeoProtection)
        $variantUri = $test->variantEntryId
            ? Entry::find()->id($test->variantEntryId)->siteId($siteId)->status(null)->one()?->uri
            : null;

        $uris = [];
        foreach (array_merge([$controlEntry->uri, $variantUri], $descendantUris) as $uri) {
            if ($uri !== null) {
                // Blitz stores the homepage as an empty URI
                $uri = $uri === Entry::HOMEPAGE_URI ? '' : (string) $uri;
                $uris[$uri] = true;
            }
        }

        $siteUriClass = self::BLITZ_SITE_URI_CLASS;

        return array_map(
            fn(string $uri) => new $siteUriClass(['siteId' => $siteId, 'uri' => $uri]),
            $this->withCachedVariants($siteId, array_keys($uris))
        );
    }

    /**
     * Return the given URIs plus every cached version of them Blitz stores as
     * a separate entry, looked up in batches rather than one query per URI.
     *
     * @param string[] $uris
     * @return string[]
     */
    private function withCachedVariants(int $siteId, array $uris): array
    {
        $uriSet = array_fill_keys($uris, true);
        $result = $uriSet;

        // Query-string pagination (pageTrigger like "?page=") is already
        // covered by the query-string match
        $pageTrigger = Craft::$app->getConfig()->getGeneral()->getPageTrigger();
        if (str_starts_with($pageTrigger, '?')) {
            $pageTrigger = null;
        }

        try {
            foreach (array_chunk($uris, 100) as $chunk) {
                $condition = ['or'];
                foreach ($chunk as $uri) {
                    $condition[] = ['like', 'uri', $this->escapeLike($uri) . '?%', false];
                    if ($pageTrigger !== null) {
                        $pagePrefix = ($uri === '' ? '' : $uri . '/') . $pageTrigger;
                        $condition[] = ['like', 'uri', $this->escapeLike($pagePrefix) . '%', false];
                    }
                }

                $cached = (new Query())
                    ->select(['uri'])
                    ->from(self::BLITZ_CACHES_TABLE)
                    ->where(['siteId' => $siteId])
                    ->andWhere($condition)
                    ->column();

                // The pagination LIKE is a prefix match that can also catch
                // unrelated pages (e.g. "news/press" for trigger "p"), so keep
                // only exact query-string and page-number forms of our URIs
                foreach ($cached as $cachedUri) {
                    foreach (self::cachedUriBases((string) $cachedUri, $pageTrigger) as $base) {
                        if (isset($uriSet[$base])) {
                            $result[(string) $cachedUri] = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Table missing or schema changed; the base URIs are still cleared
        }

        return array_map('strval', array_keys($result));
    }

    /**
     * The URIs a cached Blitz URI could be a variant of:
     * `news?x` -> news; `news/p2` -> news; `news/p2?x` -> news/p2 and news;
     * `p3` (homepage page 3) -> ''. Returns [] for anything else.
     *
     * @param string|null $pageTrigger Path-based page trigger (e.g. "p"), or null
     * @return string[]
     */
    public static function cachedUriBases(string $cachedUri, ?string $pageTrigger): array
    {
        $bases = [];
        $queryPos = strpos($cachedUri, '?');
        $path = $queryPos === false ? $cachedUri : substr($cachedUri, 0, $queryPos);

        if ($queryPos !== false) {
            $bases[] = $path;
        }

        $pagePattern = '/^(?:(.*)\/)?' . preg_quote((string) $pageTrigger, '/') . '\d+$/s';
        if ($pageTrigger !== null && $pageTrigger !== '' && preg_match($pagePattern, $path, $m)) {
            $bases[] = $m[1] ?? '';
        }

        return $bases;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Get the Blitz plugin instance if it's installed and enabled.
     * Protected so tests can supply a fake or simulate Blitz being absent.
     *
     * @return object|null \putyourlightson\blitz\Blitz
     */
    protected function getBlitz(): ?object
    {
        $blitzClass = self::BLITZ_CLASS;

        if (!class_exists($blitzClass) || !Craft::$app->getPlugins()->isPluginEnabled('blitz')) {
            return null;
        }

        return $blitzClass::$plugin ?? null;
    }
}
