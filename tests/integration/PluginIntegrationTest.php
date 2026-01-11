<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use livehand\abtestcraft\ABTestCraft;

/**
 * Plugin Integration Test
 *
 * Tests plugin functionality with full Craft CMS context.
 */
class PluginIntegrationTest extends Unit
{
    /**
     * Test that the plugin is properly installed and loaded
     */
    public function testPluginIsInstalled(): void
    {
        $plugin = ABTestCraft::getInstance();

        $this->assertNotNull($plugin, 'Split Test plugin should be installed');
        $this->assertInstanceOf(ABTestCraft::class, $plugin);
    }

    /**
     * Test that Craft application is available
     */
    public function testCraftAppIsAvailable(): void
    {
        $this->assertNotNull(Craft::$app, 'Craft::$app should be available');
    }

    /**
     * Test that plugin services are accessible
     */
    public function testServicesAreAccessible(): void
    {
        $plugin = ABTestCraft::getInstance();

        $this->assertNotNull($plugin->tests, 'Tests service should be accessible');
        $this->assertNotNull($plugin->tracking, 'Tracking service should be accessible');
        $this->assertNotNull($plugin->stats, 'Stats service should be accessible');
        $this->assertNotNull($plugin->assignment, 'Assignment service should be accessible');
        $this->assertNotNull($plugin->goals, 'Goals service should be accessible');
    }

    /**
     * Test that settings can be retrieved
     */
    public function testSettingsAreAccessible(): void
    {
        $settings = ABTestCraft::getInstance()->getSettings();

        $this->assertNotNull($settings);
        $this->assertIsFloat($settings->significanceThreshold);
    }
}
