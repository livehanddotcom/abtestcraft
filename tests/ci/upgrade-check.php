<?php

/**
 * Upgrade test, in two steps around `craft up`:
 *
 *   php upgrade-check.php <site-dir> before   (with the old release installed)
 *   php upgrade-check.php <site-dir> after    (with the new code, after `craft up`)
 *
 * "before" creates a running test and sends real visitor traffic through the
 * old release (page views and conversions over HTTP, so the data is exactly
 * what that release records). "after" checks that the results and visitors
 * survived the upgrade, and that returning visitors still see their version,
 * then removes the test so the integration tests start clean.
 *
 * Starts PHP's built-in web server for the site on port 8081 while it runs.
 */

declare(strict_types=1);

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;

[$script, $siteDir, $step] = array_pad($argv, 3, null);
if (!$siteDir || !is_file($siteDir . '/bootstrap.php') || !in_array($step, ['before', 'after'], true)) {
    fwrite(STDERR, "Usage: php upgrade-check.php <site-dir> before|after\n");
    exit(1);
}

require $siteDir . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

const HANDLE = 'upgradecheck'; // lowercase: 1.0.x handles allowed nothing else
const BASE_URL = 'http://127.0.0.1:8081';
const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

$expectedFile = $siteDir . '/storage/upgrade-check.json';
$failures = [];
$check = function(bool $ok, string $what) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . "\n";
    if (!$ok) {
        $failures[] = $what;
    }
};

$server = startServer($siteDir);
try {
    if ($step === 'before') {
        before($expectedFile);
    } else {
        after($expectedFile, $check);
    }
} finally {
    proc_terminate($server);
}

if ($failures) {
    fwrite(STDERR, count($failures) . " upgrade check(s) failed\n");
    exit(1);
}
echo "Upgrade check ({$step}) passed\n";

// ========================================
// Steps
// ========================================

function before(string $expectedFile): void
{
    $db = Craft::$app->getDb();
    $entries = (new Query())->select(['e.id', 'es.slug'])
        ->from(['e' => '{{%elements}}'])
        ->innerJoin(['es' => '{{%elements_sites}}'], '[[es.elementId]] = [[e.id]]')
        ->where(['es.slug' => ['upgrade-original', 'upgrade-variant']])
        ->pairs();
    $ids = array_flip(array_map('strval', $entries));
    if (count($ids) !== 2) {
        fail('The upgrade pages are missing (tests/ci/seed.php)');
    }

    // Only columns every release has, so this works on the oldest one
    $now = Db::prepareDateForDb(new DateTime());
    $db->createCommand()->insert('{{%abtestcraft_tests}}', [
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'name' => 'Upgrade check',
        'handle' => HANDLE,
        'hypothesis' => 'Written before the upgrade.',
        'status' => 'running',
        'controlEntryId' => (int) $ids['upgrade-original'],
        'variantEntryId' => (int) $ids['upgrade-variant'],
        'trafficSplit' => 50,
        'goalType' => 'phone',
        'startedAt' => Db::prepareDateForDb(new DateTime('-2 days')),
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
    $testId = (int) $db->getLastInsertID();
    $db->createCommand()->insert('{{%abtestcraft_goals}}', [
        'testId' => $testId,
        'goalType' => 'phone',
        'isEnabled' => true,
        'config' => '[]',
        'sortOrder' => 0,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    // 40 visitors; every third one taps the phone link
    $expected = ['visitors' => ['control' => 0, 'variant' => 0], 'converted' => ['control' => 0, 'variant' => 0], 'returning' => []];
    for ($i = 0; $i < 40; $i++) {
        $page = request('GET', '/pages/upgrade-original');
        $variant = $page['cookies']['_abtestcraft_1_' . HANDLE] ?? null;
        if (!in_array($variant, ['control', 'variant'], true)) {
            fail("Visitor {$i} wasn't assigned (HTTP {$page['status']})");
        }
        $expected['visitors'][$variant]++;

        if ($i % 3 === 0) {
            // 1.0.x wanted a CSRF token, which it put in the tracking config
            $data = ['testHandle' => HANDLE, 'conversionType' => 'phone'];
            if (preg_match('/"csrfTokenName":"([^"]+)","csrfToken":"([^"]+)"/', $page['body'], $csrf)) {
                $data[stripslashes($csrf[1])] = stripslashes($csrf[2]);
            }
            $result = request('POST', '/actions/abtestcraft/track/convert', $data, $page['cookies']);
            if (($result['json']['success'] ?? false) !== true) {
                fail("Conversion {$i} wasn't counted: {$result['body']}");
            }
            $expected['converted'][$variant]++;
        }
        if ($i < 4) {
            $expected['returning'][] = ['cookies' => $page['cookies'], 'variant' => $variant, 'converted' => $i % 3 === 0];
        }
    }

    file_put_contents($expectedFile, json_encode($expected, JSON_PRETTY_PRINT));
    printf(
        "Recorded %d visitors (%d control, %d variant) and %d conversions with %s\n",
        array_sum($expected['visitors']),
        $expected['visitors']['control'],
        $expected['visitors']['variant'],
        array_sum($expected['converted']),
        Craft::$app->getPlugins()->getPluginInfo('abtestcraft')['version'] ?? '?',
    );
}

function after(string $expectedFile, callable $check): void
{
    $expected = json_decode((string) file_get_contents($expectedFile), true);
    if (!$expected) {
        fail("No {$expectedFile}: run the before step first");
    }

    $test = (new Query())->from('{{%abtestcraft_tests}}')->where(['handle' => HANDLE])->one();
    $check($test !== null && $test['status'] === 'running' && $test['name'] === 'Upgrade check', 'The test is still there and running');
    if (!$test) {
        return;
    }
    $testId = (int) $test['id'];
    $check((bool) $test['serveWinner'] === false && $test['whenWinnerFound'] === 'notify', 'New settings have their defaults');
    $check((int) (new Query())->from('{{%abtestcraft_goals}}')->where(['testId' => $testId])->count() === 1, 'Its goal is still there');

    $visitors = (new Query())->select(['variant', 'n' => 'COUNT(*)'])->from('{{%abtestcraft_visitors}}')
        ->where(['testId' => $testId])->groupBy(['variant'])->pairs();
    $check(
        (int) ($visitors['control'] ?? 0) === $expected['visitors']['control'] && (int) ($visitors['variant'] ?? 0) === $expected['visitors']['variant'],
        'Visitor records kept: ' . json_encode($visitors),
    );

    // The results come from the daily stats, which the upgrade rebuilds per visitor
    $sum = fn(string $column, ?string $goalType) => (new Query())->select(['variant', 'n' => "SUM([[{$column}]])"])
        ->from('{{%abtestcraft_daily_stats}}')->where(['testId' => $testId, 'goalType' => $goalType])
        ->groupBy(['variant'])->pairs();
    $same = fn(array $actual, array $wanted) => (int) ($actual['control'] ?? 0) === $wanted['control']
        && (int) ($actual['variant'] ?? 0) === $wanted['variant'];
    $check($same($sum('visitors', null), $expected['visitors']), 'Visitors in the results: ' . json_encode($sum('visitors', null)));
    $check($same($sum('convertedVisitors', null), $expected['converted']), 'Converted visitors in the results: ' . json_encode($sum('convertedVisitors', null)));
    $check($same($sum('convertedVisitors', 'phone'), $expected['converted']), 'Phone conversions in the results: ' . json_encode($sum('convertedVisitors', 'phone')));
    // One slot per goal type, plus the '*' slot for first-conversion-only counting
    $slots = (new Query())->select(['goalType', 'n' => 'COUNT(*)'])->from('{{%abtestcraft_visitor_goals}}')
        ->where(['testId' => $testId])->groupBy(['goalType'])->pairs();
    $converted = array_sum($expected['converted']);
    $check(
        (int) ($slots['phone'] ?? 0) === $converted && (int) ($slots['*'] ?? 0) === $converted && count($slots) === 2,
        'Each conversion has its goal records: ' . json_encode($slots),
    );

    // Returning visitors keep their version, and aren't counted again
    foreach ($expected['returning'] as $i => $visitor) {
        $page = request('GET', '/pages/upgrade-original', [], $visitor['cookies']);
        $title = preg_match('#<h1 id="page-title">(.*?)</h1>#', $page['body'], $m) ? $m[1] : null;
        $wanted = $visitor['variant'] === 'variant' ? 'Upgrade variant' : 'Upgrade original';
        $check($title === $wanted, "Returning visitor {$i} still sees the {$visitor['variant']} ({$title})");
    }
    $check(
        (int) (new Query())->from('{{%abtestcraft_visitors}}')->where(['testId' => $testId])->count() === array_sum($expected['visitors']),
        'Returning visitors not counted again',
    );

    // A visitor who converted before the upgrade converts again: counted once
    foreach ($expected['returning'] as $i => $visitor) {
        if ($visitor['converted']) {
            request('POST', '/actions/abtestcraft/track/convert', ['testHandle' => HANDLE, 'conversionType' => 'phone'], $visitor['cookies']);
        }
    }
    $check($same($sum('convertedVisitors', 'phone'), $expected['converted']), 'Converting again after the upgrade is not counted twice');

    // Clean up, so the integration tests start with no tests
    $db = Craft::$app->getDb();
    foreach (['abtestcraft_visitor_goals', 'abtestcraft_daily_stats', 'abtestcraft_visitors', 'abtestcraft_goals'] as $table) {
        $db->createCommand()->delete("{{%{$table}}}", ['testId' => $testId])->execute();
    }
    $db->createCommand()->delete('{{%abtestcraft_tests}}', ['id' => $testId])->execute();
}

// ========================================
// Helpers
// ========================================

function fail(string $message): never
{
    fwrite(STDERR, "Upgrade check failed: {$message}\n");
    exit(1);
}

/**
 * @return resource
 */
function startServer(string $siteDir)
{
    $log = $siteDir . '/storage/logs/upgrade-check-server.log';
    @mkdir(dirname($log), 0777, true);
    $server = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-S', '127.0.0.1:8081', '-t', $siteDir . '/web'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $siteDir,
    );
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', 8081)) {
            return $server;
        }
        usleep(100000);
    }
    fail("The web server didn't start (see {$log})");
}

/**
 * @param array<string, string> $data
 * @param array<string, string> $cookies
 * @return array{status: int, cookies: array<string, string>, body: string, json: mixed}
 */
function request(string $method, string $path, array $data = [], array $cookies = []): array
{
    $setCookies = [];
    $curl = curl_init(BASE_URL . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => BROWSER,
        CURLOPT_HEADERFUNCTION => function($curl, string $line) use (&$setCookies): int {
            if (preg_match('/^set-cookie:\s*([^=;]+)=([^;]*)/i', $line, $m)) {
                $setCookies[urldecode($m[1])] = urldecode($m[2]);
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
    if (!is_string($body)) {
        fail("{$method} {$path}: " . curl_error($curl));
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($status >= 500) {
        fail("{$method} {$path} returned {$status}: " . substr(strip_tags($body), 0, 800));
    }

    return ['status' => $status, 'cookies' => array_merge($cookies, $setCookies), 'body' => $body, 'json' => json_decode($body, true)];
}
