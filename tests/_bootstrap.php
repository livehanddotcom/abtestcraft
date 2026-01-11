<?php

/**
 * Split Test Plugin - Test Bootstrap
 *
 * This bootstrap file supports both:
 * - Unit tests: Minimal Yii app for pure logic testing (fast)
 * - Integration tests: Full Craft app with database (comprehensive)
 *
 * The bootstrap mode is determined by the INTEGRATION_TEST environment variable
 * or by detecting 'integration' in the command line arguments.
 */

declare(strict_types=1);

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

// Define paths for test environment
// CRAFT_ROOT_PATH must be defined first - it points to the parent Craft installation
define('CRAFT_ROOT_PATH', dirname(__DIR__, 3));
define('CRAFT_VENDOR_PATH', CRAFT_ROOT_PATH . DIRECTORY_SEPARATOR . 'vendor');

const CRAFT_TESTS_PATH = __DIR__;
const CRAFT_STORAGE_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'storage';
const CRAFT_TEMPLATES_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'templates';
const CRAFT_CONFIG_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'config';
const CRAFT_MIGRATIONS_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'migrations';
const CRAFT_TRANSLATIONS_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'translations';

// Check if we're running integration tests BEFORE loading autoloaders
// Integration tests need Craft's TestSetup to handle everything including Yii
$isIntegrationTest = getenv('INTEGRATION_TEST') === 'true' ||
    (isset($_SERVER['argv']) && in_array('integration', $_SERVER['argv'], true));

if ($isIntegrationTest) {
    // For integration tests, only load the parent autoloader
    // Craft's TestSetup will handle Yii and everything else
    $parentAutoload = CRAFT_VENDOR_PATH . '/autoload.php';
    if (file_exists($parentAutoload)) {
        require_once $parentAutoload;
    }

    // Load environment variables from tests/.env
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
    }

    // Let Craft's TestSetup configure everything
    if (class_exists('craft\test\TestSetup')) {
        $devMode = true;
        craft\test\TestSetup::configureCraft();
    }
} else {
    // For unit tests, load autoloaders and set up minimal Yii app

    // Load parent project's autoloader first (includes Craft, Yii, and phpdotenv)
    $parentAutoload = CRAFT_VENDOR_PATH . '/autoload.php';
    if (file_exists($parentAutoload)) {
        require_once $parentAutoload;
    }

    // Also load plugin's own autoloader for plugin classes
    $pluginAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($pluginAutoload)) {
        require_once $pluginAutoload;
    }

    // Yii class must be explicitly required (it's not autoloadable)
    $yiiFile = CRAFT_VENDOR_PATH . '/yiisoft/yii2/Yii.php';
    if (!class_exists('Yii') && file_exists($yiiFile)) {
        require_once $yiiFile;
    }

    // Load environment variables from tests/.env
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
    }

    // Create minimal Yii console app for unit tests (validators, etc.)
    if (class_exists('Yii') && !isset(\Yii::$app)) {
        new \yii\console\Application([
            'id' => 'test-app',
            'basePath' => dirname(__DIR__),
            'vendorPath' => CRAFT_VENDOR_PATH,
        ]);
    }
}
