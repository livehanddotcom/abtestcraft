<?php

/**
 * Test configuration that uses the parent Craft project's database
 * This allows integration tests to access existing entries for FK constraints
 */

use craft\test\TestSetup;
use craft\db\Connection;
use craft\helpers\App;

$testConfig = TestSetup::createTestCraftObjectConfig();

// Override db component to use parent Craft project's database
// Use dsn format for Craft's Connection class
$testConfig['components']['db'] = [
    'class' => Connection::class,
    'dsn' => 'pgsql:host=' . (App::env('CRAFT_DB_SERVER') ?: 'db') .
             ';port=' . (App::env('CRAFT_DB_PORT') ?: '5432') .
             ';dbname=' . (App::env('CRAFT_DB_DATABASE') ?: 'db'),
    'username' => App::env('CRAFT_DB_USER') ?: 'db',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: 'db',
    'schemaMap' => [
        'pgsql' => [
            'class' => 'craft\db\pgsql\Schema',
            'defaultSchema' => App::env('CRAFT_DB_SCHEMA') ?: 'public',
        ],
    ],
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
];

return $testConfig;
