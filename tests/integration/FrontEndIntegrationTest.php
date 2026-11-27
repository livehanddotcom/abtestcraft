<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;

/**
 * What a site visitor gets: real HTTP requests to the front end of a running
 * Craft site (which shares this suite's database), checking the page shown,
 * cookies, cache headers, conversion tracking, served winners, bots and
 * consent.
 *
 * Needs ABTESTCRAFT_TEST_SITE_URL (in tests/.env) pointing at the site, and the
 * "Pages" section that tests/ci/seed.php creates (pages/{slug}, template
 * _page). Skipped otherwise. tests/ci/setup-site.sh sets both up.
 */
class FrontEndIntegrationTest extends Unit
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private string $baseUrl;

    /** @var array<string, Entry> */
    private array $pages = [];

    /** @var int[] */
    private array $testIds = [];

    private ?string $configFile = null;

    protected function _before(): void
    {
        $url = App::env('ABTESTCRAFT_TEST_SITE_URL');
        if (!$url) {
            $this->markTestSkipped('Set ABTESTCRAFT_TEST_SITE_URL to run the front-end tests (see tests/ci/setup-site.sh)');
        }
        $this->baseUrl = rtrim((string) $url, '/');

        foreach (['original-page', 'variant-page', 'contact'] as $slug) {
            $entry = Entry::find()->section('pages')->slug($slug)->status(null)->one();
            if (!$entry) {
                $this->markTestSkipped('Needs the Pages section from tests/ci/seed.php');
            }
            $this->pages[$slug] = $entry;
        }

        // Another test on the original page would take over its requests
        $others = TestRecord::find()->where(['controlEntryId' => $this->pages['original-page']->id])->count();
        $this->assertSame(0, (int) $others, 'No other tests on the original page');
    }

    protected function _after(): void
    {
        foreach ($this->testIds as $id) {
            Craft::$app->getDb()->createCommand()->delete('{{%abtestcraft_visitor_goals}}', ['testId' => $id])->execute();
            DailyStatsRecord::deleteAll(['testId' => $id]);
            VisitorRecord::deleteAll(['testId' => $id]);
            GoalRecord::deleteAll(['testId' => $id]);
            TestRecord::deleteAll(['id' => $id]);
        }
        if ($this->configFile !== null) {
            @unlink($this->configFile);
        }
    }

    // ========================================
    // Running tests
    // ========================================

    public function testAVisitorIsAssignedAndKeepsTheirVersion(): void
    {
        $test = $this->createTest(['trafficSplit' => 100]);

        $response = $this->get('/pages/original-page');
        $this->assertSame(200, $response['status']);
        $this->assertSame('Variant page', $this->title($response), 'The variant replaces the original');
        $this->assertPrivate($response);
        $this->assertSame('variant', $response['cookies']["_abtestcraft_{$test->siteId}_{$test->handle}"] ?? null);
        $this->assertSame('1', $response['cookies']['_abtestcraft_on'] ?? null);
        $visitorId = $response['cookies']['_abtestcraft_vid'] ?? null;
        $this->assertNotNull($visitorId, 'Visitor ID cookie');
        $this->assertStringContainsString('"testHandle":"' . $test->handle . '"', $response['body'], 'Tracking script');
        $this->assertStringContainsString('"variant":"variant"', $response['body']);

        // Coming back: same version, no new assignment
        for ($i = 0; $i < 3; $i++) {
            $again = $this->get('/pages/original-page', $response['cookies']);
            $this->assertSame('Variant page', $this->title($again));
            $this->assertArrayNotHasKey('_abtestcraft_vid', $again['newCookies'], 'No new visitor ID');
        }

        $this->assertSame(1, $this->visitorCount($test), 'Counted once');
        $this->assertSame('variant', (new Query())->select(['variant'])->from('{{%abtestcraft_visitors}}')
            ->where(['testId' => $test->id, 'visitorId' => $visitorId])->scalar());
        $this->assertSame(['control' => 0, 'variant' => 1], $this->dailyVisitors($test));
    }

    public function testTheOriginalIsShownToItsVisitors(): void
    {
        $test = $this->createTest(['trafficSplit' => 0]);

        $response = $this->get('/pages/original-page');
        $this->assertSame('Original page', $this->title($response));
        $this->assertSame('control', $response['cookies']["_abtestcraft_{$test->siteId}_{$test->handle}"] ?? null);
        $this->assertSame(['control' => 1, 'variant' => 0], $this->dailyVisitors($test));
    }

    public function testVisitorsAreSplitBetweenBothVersions(): void
    {
        $test = $this->createTest(['trafficSplit' => 50]);

        $titles = [];
        for ($i = 0; $i < 30; $i++) {
            $titles[] = $this->title($this->get('/pages/original-page'));
        }

        // All 30 the same by chance: about 1 in 500 million
        $this->assertEqualsCanonicalizing(['Original page', 'Variant page'], array_values(array_unique($titles)));
        $this->assertSame(30, $this->visitorCount($test));
    }

    public function testConversionsAreCountedOncePerVisitor(): void
    {
        $test = $this->createTest(['trafficSplit' => 100]);
        $cookies = $this->get('/pages/original-page')['cookies'];

        $result = $this->post('/actions/abtestcraft/track/convert', ['testHandle' => $test->handle, 'conversionType' => 'phone'], $cookies);
        $this->assertTrue($result['json']['success'] ?? null, 'Phone click counted');
        $result = $this->post('/actions/abtestcraft/track/convert', ['testHandle' => $test->handle, 'conversionType' => 'phone'], $cookies);
        $this->assertSame(1, $this->convertedVisitors($test, 'phone'), 'A second click by the same visitor counts once');

        // Someone who was never assigned can't convert, and gets no cookie
        $stranger = $this->post('/actions/abtestcraft/track/convert', ['testHandle' => $test->handle, 'conversionType' => 'phone']);
        $this->assertFalse($stranger['json']['success'] ?? null);
        $this->assertArrayNotHasKey('_abtestcraft_vid', $stranger['newCookies']);

        // Unknown goal types are refused
        $bad = $this->post('/actions/abtestcraft/track/convert', ['testHandle' => $test->handle, 'conversionType' => 'nope'], $cookies);
        $this->assertFalse($bad['json']['success'] ?? null);

        $this->assertSame(1, $this->convertedVisitors($test, 'phone'));
    }

    public function testPageVisitGoals(): void
    {
        $test = $this->createTest(['trafficSplit' => 100]);
        $cookies = $this->get('/pages/original-page')['cookies'];

        $other = $this->post('/actions/abtestcraft/track/page', ['url' => $this->baseUrl . '/pages/variant-page'], $cookies);
        $this->assertSame(0, $this->convertedVisitors($test, 'page'), 'Another page is not the goal');

        $result = $this->post('/actions/abtestcraft/track/page', ['url' => $this->baseUrl . '/pages/contact?from=menu'], $cookies);
        $this->assertTrue($result['json']['success'] ?? null);
        $this->assertSame(1, $this->convertedVisitors($test, 'page'), 'Visiting the goal page counts');
        $this->assertTrue($other['json']['success'] ?? null);
    }

    public function testTheVariantsOwnUrlIsNotIndexed(): void
    {
        $this->createTest(['trafficSplit' => 100]);

        $response = $this->get('/pages/variant-page');
        $this->assertSame(200, $response['status']);
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex,follow">/', $response['body']);
        $this->assertMatchesRegularExpression('#<link href="[^"]*/pages/original-page" rel="canonical">#', $response['body']);
        $this->assertPrivate($response);
    }

    // ========================================
    // Visitors who aren't counted
    // ========================================

    public function testBotsSeeTheOriginalAndArentCounted(): void
    {
        $test = $this->createTest(['trafficSplit' => 100]);

        foreach (['curl/8.5.0', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', ''] as $agent) {
            $response = $this->get('/pages/original-page', [], $agent);
            $this->assertSame('Original page', $this->title($response), "Bot: {$agent}");
            $this->assertSame([], $this->abtestcraftCookies($response), "No cookies for: {$agent}");
            $this->assertPrivate($response);
        }

        $this->assertSame(0, $this->visitorCount($test));
    }

    public function testConsentIsRequiredWhenSwitchedOn(): void
    {
        $this->useSiteConfig(['requireConsent' => true]);
        $test = $this->createTest(['trafficSplit' => 100]);

        $noAnswer = $this->get('/pages/original-page');
        $this->assertSame('Original page', $this->title($noAnswer), 'No consent yet: the original');
        $this->assertSame([], $this->abtestcraftCookies($noAnswer));

        $granted = $this->get('/pages/original-page', ['_abtestcraft_consent' => '1']);
        $this->assertSame('Variant page', $this->title($granted), 'Consent given: in the test');
        $this->assertArrayHasKey('_abtestcraft_vid', $granted['newCookies']);

        // Withdrawing consent removes the tracking cookies
        $cookies = array_merge($granted['cookies'], ['_abtestcraft_consent' => '0']);
        $withdrawn = $this->get('/pages/original-page', $cookies);
        $this->assertSame('Original page', $this->title($withdrawn));
        $this->assertSame('', $withdrawn['newCookies']['_abtestcraft_vid'] ?? null, 'Visitor ID cookie removed');

        $this->assertSame(1, $this->visitorCount($test));
    }

    // ========================================
    // Tests that aren't running
    // ========================================

    public function testDraftsAndPausedTestsDontChangeThePage(): void
    {
        foreach ([Test::STATUS_DRAFT, Test::STATUS_PAUSED] as $status) {
            $test = $this->createTest(['status' => $status, 'trafficSplit' => 100]);

            $response = $this->get('/pages/original-page');
            $this->assertSame('Original page', $this->title($response), $status);
            $this->assertSame([], $this->abtestcraftCookies($response), $status);
            $this->assertStringNotContainsString('"testHandle"', $response['body'], "No tracking script: {$status}");

            TestRecord::deleteAll(['id' => $test->id]);
        }
    }

    public function testAServedWinnerIsShownToEveryoneWithoutCookies(): void
    {
        $this->createTest([
            'status' => Test::STATUS_COMPLETED,
            'winnerVariant' => Test::VARIANT_VARIANT,
            'serveWinner' => true,
            'endedAt' => '-1 day',
        ]);

        foreach ([self::BROWSER, 'curl/8.5.0'] as $agent) {
            $response = $this->get('/pages/original-page', [], $agent);
            $this->assertSame('Variant page', $this->title($response), "Winner shown to: {$agent}");
            $this->assertSame([], $this->abtestcraftCookies($response));
            $this->assertStringNotContainsString('private', strtolower($response['headers']['cache-control'] ?? ''), 'Cacheable');
        }
    }

    public function testAWinnerThatIsntServedLeavesTheOriginal(): void
    {
        $this->createTest(['status' => Test::STATUS_COMPLETED, 'winnerVariant' => Test::VARIANT_VARIANT, 'endedAt' => '-1 day']);

        $this->assertSame('Original page', $this->title($this->get('/pages/original-page')));
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @param array<string, mixed> $attributes
     */
    private function createTest(array $attributes = []): Test
    {
        $record = new TestRecord();
        $record->siteId = (int) $this->pages['original-page']->siteId;
        $record->name = 'Front-end test';
        $record->handle = 'frontEnd' . StringHelper::randomString(8);
        $record->status = $attributes['status'] ?? Test::STATUS_RUNNING;
        $record->controlEntryId = $this->pages['original-page']->id;
        $record->variantEntryId = $this->pages['variant-page']->id;
        $record->trafficSplit = $attributes['trafficSplit'] ?? 50;
        $record->goalType = Goal::TYPE_PHONE;
        $record->whenWinnerFound = Test::WINNER_NOTIFY;
        $record->winnerVariant = $attributes['winnerVariant'] ?? null;
        $record->serveWinner = $attributes['serveWinner'] ?? false;
        $record->startedAt = Db::prepareDateForDb(new DateTime('-1 day'));
        $record->endedAt = isset($attributes['endedAt']) ? Db::prepareDateForDb(new DateTime($attributes['endedAt'])) : null;
        $record->save(false);
        $this->testIds[] = (int) $record->id;

        $goals = [
            Goal::TYPE_PHONE => [],
            Goal::TYPE_PAGE => ['pageUrl' => '/pages/contact', 'matchType' => Goal::MATCH_EXACT],
        ];
        $order = 0;
        foreach ($goals as $type => $config) {
            $goal = new GoalRecord();
            $goal->testId = $record->id;
            $goal->goalType = $type;
            $goal->isEnabled = true;
            $goal->config = $config;
            $goal->sortOrder = $order++;
            $goal->save(false);
        }

        $test = \livehand\abtestcraft\ABTestCraft::getInstance()->tests->getTestById((int) $record->id);
        $this->assertNotNull($test);

        return $test;
    }

    /**
     * Settings in the site's config/abtestcraft.php (read on every request)
     *
     * @param array<string, mixed> $settings
     */
    private function useSiteConfig(array $settings): void
    {
        $file = CRAFT_ROOT_PATH . '/config/abtestcraft.php';
        if (file_exists($file)) {
            $this->markTestSkipped("The site already has {$file}");
        }
        file_put_contents($file, '<?php return ' . var_export($settings, true) . ';');
        $this->configFile = $file;
    }

    /**
     * @param array<string, string> $cookies
     * @return array{status: int, headers: array<string, string>, cookies: array<string, string>, newCookies: array<string, string>, body: string, json: mixed}
     */
    private function get(string $path, array $cookies = [], string $userAgent = self::BROWSER): array
    {
        return $this->request('GET', $path, [], $cookies, $userAgent);
    }

    /**
     * @param array<string, string> $data
     * @param array<string, string> $cookies
     * @return array{status: int, headers: array<string, string>, cookies: array<string, string>, newCookies: array<string, string>, body: string, json: mixed}
     */
    private function post(string $path, array $data, array $cookies = []): array
    {
        return $this->request('POST', $path, $data, $cookies, self::BROWSER);
    }

    /**
     * @param array<string, string> $data
     * @param array<string, string> $cookies
     * @return array{status: int, headers: array<string, string>, cookies: array<string, string>, newCookies: array<string, string>, body: string, json: mixed}
     */
    private function request(string $method, string $path, array $data, array $cookies, string $userAgent): array
    {
        $headers = [];
        $setCookies = [];
        $curl = curl_init($this->baseUrl . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
            CURLOPT_HEADERFUNCTION => function($curl, string $line) use (&$headers, &$setCookies): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name === 'set-cookie') {
                        // name=value; attributes. A removed cookie comes back empty.
                        [$pair] = explode(';', $value, 2);
                        [$cookieName, $cookieValue] = array_pad(explode('=', $pair, 2), 2, '');
                        $removed = preg_match('/max-age=0|expires=[^;]*19[67]0/i', $value) === 1;
                        $setCookies[urldecode($cookieName)] = $removed ? '' : urldecode($cookieValue);
                    } else {
                        $headers[$name] = $value;
                    }
                }

                return strlen($line);
            },
        ]);
        if ($cookies) {
            curl_setopt($curl, CURLOPT_COOKIE, implode('; ', array_map(
                fn($name, $value) => $name . '=' . rawurlencode((string) $value),
                array_keys($cookies),
                $cookies,
            )));
        }
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $body = curl_exec($curl);
        $this->assertIsString($body, "{$method} {$path}: " . curl_error($curl));
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->assertLessThan(500, $status, "{$method} {$path} returned {$status}: " . substr(strip_tags($body), 0, 500));

        return [
            'status' => $status,
            'headers' => $headers,
            // What the browser holds now, and what this response set
            'cookies' => array_merge($cookies, $setCookies),
            'newCookies' => $setCookies,
            'body' => $body,
            'json' => json_decode($body, true),
        ];
    }

    /**
     * @param array{body: string} $response
     */
    private function title(array $response): string
    {
        $this->assertMatchesRegularExpression('#<h1 id="page-title">(.*?)</h1>#', $response['body'], 'Page title');
        preg_match('#<h1 id="page-title">(.*?)</h1>#', $response['body'], $match);

        return html_entity_decode($match[1]);
    }

    /**
     * ABTestCraft cookies the response set
     *
     * @param array<string, mixed> $response
     * @return array<string, string>
     */
    private function abtestcraftCookies(array $response): array
    {
        return array_filter(
            $response['newCookies'],
            fn($name) => str_starts_with((string) $name, '_abtestcraft_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array{headers: array<string, string>} $response
     */
    private function assertPrivate(array $response): void
    {
        $cacheControl = strtolower($response['headers']['cache-control'] ?? '');
        $this->assertStringContainsString('private', $cacheControl, 'Not stored by shared caches');
        $this->assertStringContainsString('no-cache', $cacheControl);
    }

    private function visitorCount(Test $test): int
    {
        return (int) VisitorRecord::find()->where(['testId' => $test->id])->count();
    }

    /**
     * @return array{control: int, variant: int}
     */
    private function dailyVisitors(Test $test): array
    {
        $counts = ['control' => 0, 'variant' => 0];
        $rows = (new Query())->select(['variant', 'visitors' => 'SUM([[visitors]])'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id, 'goalType' => null])
            ->groupBy(['variant'])->all();
        foreach ($rows as $row) {
            $counts[$row['variant']] = (int) $row['visitors'];
        }

        return $counts;
    }

    private function convertedVisitors(Test $test, string $goalType): int
    {
        return (int) (new Query())->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id, 'goalType' => $goalType])
            ->sum('[[convertedVisitors]]');
    }
}
