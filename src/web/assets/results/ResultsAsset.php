<?php

declare(strict_types=1);

namespace livehand\abtestcraft\web\assets\results;

use craft\web\AssetBundle;

/**
 * Chart.js for the results page, bundled with the plugin so the control panel
 * doesn't load scripts from a third-party CDN (and works offline or with a
 * strict Content Security Policy).
 *
 * dist/chart.umd.js is Chart.js 4.4.1 from npm, unmodified (MIT licensed,
 * see dist/chart.js-LICENSE.md).
 */
class ResultsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->js = [
            'chart.umd.js',
        ];

        parent::init();
    }
}
