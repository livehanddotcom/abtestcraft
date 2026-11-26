<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\web\Request;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Test;

/**
 * Decides which requests take part in tests.
 *
 * Previews, known bots, (by default) people logged in with control panel
 * access and (in consent mode) visitors without consent see the control and
 * aren't assigned or counted. Without this,
 * crawlers (which don't keep cookies) became a new visitor on every request,
 * and editors checking their own pages skewed the results.
 *
 * People with control panel access can still see a variant on the real page
 * with `?abtestcraft=variant` (or `=control`); those views aren't counted.
 */
class VisitorFilterService extends Component
{
    /** Query parameter editors use to preview a variant on the tested page */
    public const PREVIEW_PARAM = 'abtestcraft';

    /**
     * User agents of crawlers, link previewers, monitoring, automation and
     * HTTP libraries. Matched case-insensitively anywhere in the user agent.
     * "bot" as a word catches Googlebot, Slackbot, TelegramBot, Discordbot and
     * the like; Cubot (a phone brand) is excluded from that.
     */
    private const BOT_PATTERN = '/(?<!cu)bot\b|bot\/|crawl|spider|slurp|scrape|archiver|headless|phantomjs'
        . '|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|statuscake|site24x7|datadog|newrelic'
        . '|facebookexternalhit|facebookcatalog|embedly|quora link preview|outbrain|vkshare'
        . '|whatsapp\/|skypeuripreview|bingpreview|preview'
        . '|validator|w3c_|feedfetcher|feedly|rss|curl\/|wget|python|httpclient|http_client|go-http'
        . '|java\/|okhttp|axios|node-fetch|undici|guzzle|libwww|perl|ruby|php\/|postman|insomnia'
        . '|gptbot|chatgpt|claude|anthropic|perplexity|bytespider|ccbot|cohere|amazonbot|applebot'
        . '|yandex|baidu|sogou|exabot|ia_archiver|semrush|ahrefs|mj12|dotbot|petalbot|duckduckgo'
        . '|google-inspectiontool|googleother|adsbot|mediapartners|apis-google|blitz/i';

    private ?bool $excluded = null;

    /**
     * Whether the current request should be left out of tests: shown the
     * control, not assigned, and not counted.
     */
    public function shouldExcludeRequest(): bool
    {
        return $this->excluded ??= $this->isPreviewRequest()
            || $this->isBot()
            || $this->isExcludedControlPanelUser()
            || !ABTestCraft::getInstance()->consent->hasConsent();
    }

    /**
     * Live Preview, shared preview links and other token requests
     */
    public function isPreviewRequest(): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return false;
        }

        return $request->getIsPreview() || $request->getToken() !== null;
    }

    /**
     * Whether the request comes from a known bot (when bot filtering is on).
     * An empty user agent counts as a bot: browsers always send one.
     */
    public function isBot(?string $userAgent = null): bool
    {
        if (!ABTestCraft::getInstance()->getSettings()->excludeBots) {
            return false;
        }

        if ($userAgent === null) {
            $request = Craft::$app->getRequest();
            if (!$request instanceof Request) {
                return false;
            }
            $userAgent = (string) $request->getUserAgent();
        }

        return trim($userAgent) === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    /**
     * Whether the visitor is logged in with control panel access and those
     * users are excluded
     */
    public function isExcludedControlPanelUser(): bool
    {
        return ABTestCraft::getInstance()->getSettings()->excludeControlPanelUsers
            && $this->isControlPanelUser();
    }

    /**
     * The variant a control panel user asked to preview with
     * `?abtestcraft=variant|control`, or null
     */
    public function getEditorPreviewVariant(): ?string
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $variant = $request->getQueryParam(self::PREVIEW_PARAM);
        if (!in_array($variant, [Test::VARIANT_CONTROL, Test::VARIANT_VARIANT], true)) {
            return null;
        }

        return $this->isControlPanelUser() ? $variant : null;
    }

    private function isControlPanelUser(): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return false;
        }

        // Reads the session only when the visitor already has a session
        // cookie, so anonymous visitors don't get a session started
        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null && $user->can('accessCp');
    }
}
