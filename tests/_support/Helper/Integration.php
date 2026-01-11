<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\Support\Helper;

use Codeception\Module;

/**
 * Integration Helper
 *
 * Provides helper methods for integration tests that require
 * full Craft CMS context.
 */
class Integration extends Module
{
    /**
     * Get the ABTestCraft plugin instance
     */
    public function getABTestCraftPlugin(): ?\livehand\abtestcraft\ABTestCraft
    {
        return \livehand\abtestcraft\ABTestCraft::getInstance();
    }
}
