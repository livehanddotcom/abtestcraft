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
     * Consent banner cookies and the text in their value that means
     * analytics/statistics consent was given
     */
    public const BANNER_PRESETS = [
        'cookiebot' => ['label' => 'Cookiebot', 'cookie' => 'CookieConsent', 'contains' => 'statistics:true'],
        'cookieyes' => ['label' => 'CookieYes', 'cookie' => 'cookieyes-consent', 'contains' => 'analytics:yes'],
        'complianz' => ['label' => 'Complianz', 'cookie' => 'cmplz_statistics', 'contains' => 'allow'],
        'onetrust' => ['label' => 'OneTrust', 'cookie' => 'OptanonConsent', 'contains' => 'C0002:1'],
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
        foreach ($this->getBannerChecks() as $check) {
            $value = $_COOKIE[$check['cookie']] ?? null;
            if (!is_string($value)) {
                continue;
            }
            if ($check['contains'] === '' || str_contains(rawurldecode($value), $check['contains'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{cookie: string, contains: string}>
     */
    private function getBannerChecks(): array
    {
        $settings = ABTestCraft::getInstance()->getSettings();

        return match ($settings->consentCookie) {
            'auto' => array_values(self::BANNER_PRESETS),
            'custom' => $settings->consentCookieName !== ''
                ? [['cookie' => $settings->consentCookieName, 'contains' => $settings->consentCookieValue]]
                : [],
            'none' => [],
            default => isset(self::BANNER_PRESETS[$settings->consentCookie])
                ? [self::BANNER_PRESETS[$settings->consentCookie]]
                : [],
        };
    }
}
