<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use livehand\abtestcraft\ABTestCraft;

/**
 * Consent mode: when "Require consent" is on, only visitors who've given
 * consent take part in tests. Everyone else sees the control, gets no
 * cookies and isn't counted.
 *
 * Consent comes from any of:
 * - Google Consent Mode: the page script watches the dataLayer's `consent`
 *   commands and records the choice in ABTestCraft's own cookie, which the
 *   server reads on the next tested page view
 * - a consent banner's own cookie (Cookiebot, CookieYes, Complianz,
 *   OneTrust, or a custom cookie), read on the server
 * - `ABTestCraft.setConsent(true|false)` in JavaScript
 *
 * An explicit "no" in ABTestCraft's cookie wins, so withdrawing consent
 * through Consent Mode or setConsent(false) takes effect even if a banner
 * cookie is stale.
 */
class ConsentService extends Component
{
    /** ABTestCraft's own record of the visitor's choice: "1" or "0" */
    public const CONSENT_COOKIE = '_abtestcraft_consent';

    /**
     * Consent banners whose own first-party cookie the server can read. The
     * check for each is in bannerGrants(). Formats from the vendors' docs:
     * - Cookiebot `CookieConsent`: `{...,statistics:true,...}`, or `-1` where
     *   consent isn't required (treated as granted)
     * - CookieYes `cookieyes-consent`: `...,analytics:yes,...`
     * - Complianz `cmplz_statistics` (`cmplz_rt_statistics` on a multisite
     *   root): `allow` or `deny`
     * - OneTrust `OptanonConsent`: `...&groups=C0001:1,C0002:1&...`; the
     *   performance group is C0002 by default but configurable per account
     * - CookieConsent (orestbida, v3) `cc_cookie`: JSON with a `categories`
     *   list; "analytics" by convention
     * Banners that keep consent in localStorage (Usercentrics, CookieConsent
     * with useLocalStorage) can't be read by the server: use Google Consent
     * Mode or ABTestCraft.setConsent() for those.
     */
    public const BANNER_PRESETS = [
        'cookiebot' => 'Cookiebot',
        'cookieyes' => 'CookieYes',
        'complianz' => 'Complianz',
        'onetrust' => 'OneTrust',
        'cookieconsent' => 'CookieConsent (orestbida)',
    ];

    /**
     * Whether consent is required at all
     */
    public function isRequired(): bool
    {
        return ABTestCraft::getInstance()->getSettings()->requireConsent;
    }

    /**
     * Whether the current visitor can take part in tests
     */
    public function hasConsent(): bool
    {
        if (!$this->isRequired()) {
            return true;
        }

        $own = $_COOKIE[self::CONSENT_COOKIE] ?? null;
        if ($own === '0') {
            return false;
        }
        if ($own === '1') {
            return true;
        }

        return $this->bannerCookieGrantsConsent();
    }

    /**
     * Without consent, remove ABTestCraft's tracking cookies (for visitors
     * who were in a test and then withdrew consent). The consent cookie
     * itself stays: it records the "no".
     */
    public function removeCookiesWithoutConsent(): void
    {
        if (!$this->isRequired() || $this->hasConsent() || headers_sent()) {
            return;
        }

        $secure = Craft::$app->getRequest()->getIsSecureConnection();
        foreach (array_keys($_COOKIE) as $name) {
            if (!is_string($name) || !str_starts_with($name, '_abtestcraft_') || $name === self::CONSENT_COOKIE) {
                continue;
            }
            setcookie($name, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $secure,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[$name]);
        }
    }

    /**
     * JavaScript for the cache-safe page script: records Google Consent Mode
     * choices in ABTestCraft's consent cookie and adds
     * `ABTestCraft.setConsent()`. Identical for every visitor. Empty when
     * consent isn't required.
     *
     * Only `update` commands (the visitor's choice) and a `default` of
     * "granted" are recorded. Banners send `default: denied` on every page
     * before re-sending the stored choice as an update; treating that default
     * as a "no" would briefly withdraw consent, and the page-visit beacon
     * firing in that moment would remove a consenting visitor's cookies.
     */
    public function getConsentScript(): string
    {
        if (!$this->isRequired()) {
            return '';
        }

        $settings = ABTestCraft::getInstance()->getSettings();
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $cookie = Json::encode(self::CONSENT_COOKIE, $flags);
        $signal = Json::encode($settings->consentModeSignal, $flags);
        $maxAge = max(1, $settings->cookieDuration) * 86400;

        return <<<JS
<script>(function(){
var C={$cookie},S={$signal};
function set(v){var c=C+'='+(v?'1':'0')+';path=/;max-age={$maxAge};SameSite=Lax';
if(location.protocol==='https:'){c+=';Secure';}document.cookie=c;
if(!v){document.cookie='_abtestcraft_on=;path=/;max-age=0';}}
var A=window.ABTestCraft=window.ABTestCraft||{};A.setConsent=function(v){set(!!v);};
if(!S){return;}
function seen(a){if(!a||a[0]!=='consent'||!a[2]||typeof a[2][S]!=='string'){return;}
var g=a[2][S]==='granted';if(a[1]==='update'||(a[1]==='default'&&g)){set(g);}}
var dl=window.dataLayer=window.dataLayer||[];for(var i=0;i<dl.length;i++){seen(dl[i]);}
var p=dl.push;dl.push=function(){for(var j=0;j<arguments.length;j++){seen(arguments[j]);}
return p.apply(dl,arguments);};
})();</script>
JS;
    }

    private function bannerCookieGrantsConsent(): bool
    {
        $settings = ABTestCraft::getInstance()->getSettings();

        if ($settings->consentCookie === 'auto') {
            foreach (array_keys(self::BANNER_PRESETS) as $preset) {
                if ($this->bannerGrants($preset)) {
                    return true;
                }
            }
            return false;
        }

        return match ($settings->consentCookie) {
            'custom' => $this->customCookieGrants(),
            'none' => false,
            default => $this->bannerGrants($settings->consentCookie),
        };
    }

    /**
     * Whether a consent banner's cookie says analytics/statistics consent was given
     */
    public function bannerGrants(string $preset): bool
    {
        $cookie = fn(string $name): ?string => is_string($_COOKIE[$name] ?? null)
            ? rawurldecode($_COOKIE[$name])
            : null;

        switch ($preset) {
            case 'cookiebot':
                $value = $cookie('CookieConsent');
                return $value !== null && ($value === '-1' || str_contains($value, 'statistics:true'));
            case 'cookieyes':
                $value = $cookie('cookieyes-consent');
                return $value !== null && preg_match('/(?:^|,)analytics:yes(?:,|$)/', $value) === 1;
            case 'complianz':
                return ($cookie('cmplz_statistics') ?? $cookie('cmplz_rt_statistics')) === 'allow';
            case 'onetrust':
                $value = $cookie('OptanonConsent');
                $group = preg_quote(ABTestCraft::getInstance()->getSettings()->consentOneTrustGroup, '/');
                return $value !== null && preg_match("/(?:^|[=,]){$group}:1(?:[,&]|$)/", $value) === 1;
            case 'cookieconsent':
                $value = $cookie('cc_cookie');
                $data = $value !== null ? json_decode($value, true) : null;
                return is_array($data) && in_array('analytics', (array) ($data['categories'] ?? []), true);
            default:
                return false;
        }
    }

    private function customCookieGrants(): bool
    {
        $settings = ABTestCraft::getInstance()->getSettings();
        $value = $settings->consentCookieName !== '' ? ($_COOKIE[$settings->consentCookieName] ?? null) : null;
        if (!is_string($value)) {
            return false;
        }

        return $settings->consentCookieValue === ''
            || str_contains(rawurldecode($value), $settings->consentCookieValue);
    }
}
