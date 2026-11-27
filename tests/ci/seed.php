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
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
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
// The title is only saved when the field layout has a Title field
$layout = new FieldLayout(['type' => Entry::class]);
$tab = new FieldLayoutTab(['layout' => $layout, 'name' => 'Content']);
$tab->setElements([new EntryTitleField()]);
$layout->setTabs([$tab]);
$entryType->setFieldLayout($layout);
if (!$entriesService->saveEntryType($entryType)) {
    $fail('the entry type', $entryType->getErrors());
}

$section = new Section([
    'name' => 'Pages',
    'handle' => 'pages',
    'type' => Section::TYPE_CHANNEL,
    // No revisions, so the entries table holds just these entries
    'enableVersioning' => false,
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

// Tests use the first ones by ID; the last two are for tests/ci/upgrade-check.php
$titles = ['Original page', 'Variant page', 'Child page', 'Contact', 'About', 'Upgrade original', 'Upgrade variant'];
foreach ($titles as $title) {
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
    $saved = Entry::find()->id($entry->id)->status(null)->one();
    if ($saved?->title !== $title) {
        $fail("entry \"{$title}\" (title came back as \"{$saved?->title}\")", []);
    }
}

echo "Seeded the Pages section with " . count($titles) . " entries\n";
