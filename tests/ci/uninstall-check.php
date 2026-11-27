<?php

/**
 * Uninstalls ABTestCraft, checks that it removed all of its tables, then
 * installs it again and checks they're back.
 *
 *   php uninstall-check.php <site-dir>
 */

declare(strict_types=1);

$siteDir = $argv[1] ?? null;
if (!$siteDir || !is_file($siteDir . '/craft')) {
    fwrite(STDERR, "Usage: php uninstall-check.php <site-dir>\n");
    exit(1);
}

require $siteDir . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$expected = [
    'abtestcraft_daily_stats',
    'abtestcraft_goals',
    'abtestcraft_rate_limits',
    'abtestcraft_state',
    'abtestcraft_test_descendants',
    'abtestcraft_tests',
    'abtestcraft_visitor_goals',
    'abtestcraft_visitors',
];

$craft = function(string ...$args) use ($siteDir): void {
    $command = array_merge([PHP_BINARY, $siteDir . '/craft'], $args, ['--interactive=0']);
    passthru(implode(' ', array_map('escapeshellarg', $command)), $code);
    if ($code !== 0) {
        fwrite(STDERR, 'Failed: craft ' . implode(' ', $args) . "\n");
        exit(1);
    }
};
$tables = function(): array {
    $db = Craft::$app->getDb();
    $db->getSchema()->refresh();
    $names = array_filter($db->getSchema()->getTableNames(), fn($name) => str_contains($name, 'abtestcraft'));
    sort($names);

    return array_values($names);
};
$installed = fn(): bool => (bool) (new craft\db\Query())->from('{{%plugins}}')->where(['handle' => 'abtestcraft'])->exists();

$failures = 0;
$check = function(bool $ok, string $what) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

$check($tables() === $expected, 'Installed: ' . implode(', ', $tables()));

$craft('plugin/uninstall', 'abtestcraft');
$check($tables() === [], 'Uninstalling removes every table' . ($tables() ? ' (left: ' . implode(', ', $tables()) . ')' : ''));
$check(!$installed(), 'Uninstalling removes the plugin record');

$craft('plugin/install', 'abtestcraft');
$check($tables() === $expected, 'Installing again creates every table');
$check($installed(), 'Installed again');

if ($failures) {
    fwrite(STDERR, "{$failures} uninstall check(s) failed\n");
    exit(1);
}
echo "Uninstall check passed\n";
