<?php

/**
 * Test configuration that uses the parent Craft project's database
 * This allows integration tests to access existing entries for FK constraints
 */

use craft\test\TestSetup;
use craft\db\Connection;
use craft\helpers\App;

$testConfig = TestSetup::createTestCraftObjectConfig();

// Override db component to use the parent Craft project's database.
// CRAFT_DB_DSN wins when set (e.g. to run the suite against MySQL); otherwise
// the DSN is built from CRAFT_DB_DRIVER (default pgsql, the ddev site's DB).
$driver = App::env('CRAFT_DB_DRIVER') ?: 'pgsql';
$dsn = App::env('CRAFT_DB_DSN') ?: sprintf(
    '%s:host=%s;port=%s;dbname=%s',
    $driver,
    App::env('CRAFT_DB_SERVER') ?: 'db',
    App::env('CRAFT_DB_PORT') ?: ($driver === 'mysql' ? '3306' : '5432'),
    App::env('CRAFT_DB_DATABASE') ?: 'db',
);

$testConfig['components']['db'] = [
    'class' => Connection::class,
    'dsn' => $dsn,
    'username' => App::env('CRAFT_DB_USER') ?: 'db',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: 'db',
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
];

if (str_starts_with($dsn, 'pgsql:')) {
    $testConfig['components']['db']['schemaMap'] = [
        'pgsql' => [
            'class' => 'craft\db\pgsql\Schema',
            'defaultSchema' => App::env('CRAFT_DB_SCHEMA') ?: 'public',
        ],
    ];
}

return $testConfig;
