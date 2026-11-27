<?php

/**
 * Adds the content the integration tests need to a fresh Craft install: a
 * "Pages" section with URLs and a few entries (tests use the first ones as the
 * original, variant and child pages).
 *
 *   php seed.php <site-dir>
 */

declare(strict_types=1);

use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;

$siteDir = $argv[1] ?? null;
if (!$siteDir || !is_file($siteDir . '/bootstrap.php')) {
    fwrite(STDERR, "Usage: php seed.php <site-dir>\n");
    exit(1);
}

require $siteDir . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$entriesService = Craft::$app->getEntries();
$site = Craft::$app->getSites()->getPrimarySite();

$fail = function(string $what, array $errors): never {
    fwrite(STDERR, "Couldn't save {$what}: " . json_encode($errors) . "\n");
    exit(1);
};

$entryType = new EntryType([
    'name' => 'Page',
    'handle' => 'page',
    'hasTitleField' => true,
]);
if (!$entriesService->saveEntryType($entryType)) {
    $fail('the entry type', $entryType->getErrors());
}

$section = new Section([
    'name' => 'Pages',
    'handle' => 'pages',
    'type' => Section::TYPE_CHANNEL,
    'siteSettings' => [
        new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => true,
            'uriFormat' => 'pages/{slug}',
            'template' => '_page',
        ]),
    ],
]);
$section->setEntryTypes([$entryType]);
if (!$entriesService->saveSection($section)) {
    $fail('the section', $section->getErrors());
}

foreach (['Original page', 'Variant page', 'Child page', 'Contact', 'About'] as $title) {
    $entry = new Entry([
        'sectionId' => $section->id,
        'typeId' => $entryType->id,
        'siteId' => $site->id,
        'title' => $title,
        'slug' => ElementHelper::generateSlug($title),
    ]);
    if (!Craft::$app->getElements()->saveElement($entry)) {
        $fail("entry \"{$title}\"", $entry->getErrors());
    }
}

echo "Seeded the Pages section with 5 entries\n";
