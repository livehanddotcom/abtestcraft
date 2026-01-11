<?php

declare(strict_types=1);

namespace livehand\abtestcraft\web\assets\tracking;

use craft\web\AssetBundle;

/**
 * Tracking asset bundle
 */
class TrackingAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->js = [
            'tracking.js',
        ];

        parent::init();
    }
}
