<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\models\Goal;

/**
 * Counts page-visit goals on any page of the site.
 *
 * Every front-end page carries a tiny beacon script. It's identical for every
 * visitor, so it's safe on pages served from Blitz or a CDN. It only sends a
 * request when the visitor has the "in a test" marker cookie (set when they're
 * assigned to a test), passing the page URL. The server then checks the
 * visitor's running tests and records a conversion for each page-visit goal
 * the URL matches.
 *
 * Before 1.1.0 the tracking script only ran on tested pages, so a goal page
 * reached any other way (a redirect after a form, or browsing a few pages
 * first) wasn't counted.
 */
class PageVisitService extends Component
{
    /**
     * Record a page visit for the current visitor.
     *
     * @return array<int, array{testHandle: string, variant: string}> The tests
     *     whose page-visit goal the URL matched (and that recorded it)
     */
    public function recordPageVisit(string $url): array
    {
        $visitorId = ABTestCraft::getInstance()->assignment->getVisitorId();
        if ($visitorId === null || $url === '') {
            return [];
        }

        $rows = (new Query())
            ->select(['testId', 'variant'])
            ->from('{{%abtestcraft_visitors}}')
            ->where(['visitorId' => $visitorId])
            ->all();

        $counted = [];
        foreach ($rows as $row) {
            $test = ABTestCraft::getInstance()->tests->getTestById((int) $row['testId']);
            if (!$test || !$test->isRunning()) {
                continue;
            }

            $goal = ABTestCraft::getInstance()->goals->getGoalByTestAndType((int) $test->id, Goal::TYPE_PAGE);
            if (!$goal || !$goal->isEnabled || !$goal->matchesPageUrl($url)) {
                continue;
            }

            if (ABTestCraft::getInstance()->tracking->recordConversion($test, Goal::TYPE_PAGE, $goal->id)) {
                $counted[] = ['testHandle' => (string) $test->handle, 'variant' => (string) $row['variant']];
            }
        }

        return $counted;
    }

    /**
     * The beacon script added to every front-end page.
     */
    public function getBeaconScript(): string
    {
        // Root-relative, so a page cached under one host name still posts to
        // the host it's viewed on (and sends the visitor's cookies)
        $actionUrl = UrlHelper::actionUrl('abtestcraft/track/page');
        $endpoint = (string) parse_url($actionUrl, PHP_URL_PATH);
        $query = parse_url($actionUrl, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $endpoint .= '?' . $query;
        }

        $marker = AssignmentService::MARKER_COOKIE;
        $endpointJson = Json::encode(
            $endpoint,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        return <<<JS
<script>(function(){
if(!/(?:^|;\\s*){$marker}=1(?:;|$)/.test(document.cookie)||!window.fetch)return;
var d=new FormData();d.append('url',location.href);
fetch({$endpointJson},{method:'POST',body:d,credentials:'same-origin',keepalive:true})
.then(function(r){return r.json();})
.then(function(j){if(j&&j.events&&window.dataLayer){j.events.forEach(function(e){window.dataLayer.push(e);});}})
.catch(function(){});
})();</script>
JS;
    }
}
