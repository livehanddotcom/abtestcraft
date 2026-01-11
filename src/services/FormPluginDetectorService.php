<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;

/**
 * Detects installed form plugins and provides configuration for smart form tracking.
 *
 * Supports:
 * - Freeform (Solspace)
 * - Formie (Verbb)
 */
class FormPluginDetectorService extends Component
{
    /**
     * Configuration for supported form plugins
     */
    private const FORM_PLUGINS = [
        'freeform' => [
            'handle' => 'freeform',
            'class' => 'Solspace\\Freeform\\Freeform',
            'name' => 'Freeform',
            'formSelector' => '.freeform-form',
            'formAttributeSelector' => 'form[data-freeform-form="{handle}"]',
            'successSelectors' => ['.ff-form-success', '.freeform-form-success'],
            'successEvent' => 'freeform-ajax-success',
        ],
        'formie' => [
            'handle' => 'formie',
            'class' => 'verbb\\formie\\Formie',
            'name' => 'Formie',
            'formSelector' => '.fui-form',
            'formAttributeSelector' => 'form[data-fui-form="{handle}"]',
            'successSelectors' => ['.fui-form-success'],
            'successEvent' => 'onAfterFormieSubmit',
        ],
    ];

    /**
     * Get all installed form plugins with their configuration
     */
    public function getInstalledFormPlugins(): array
    {
        $installed = [];

        foreach (self::FORM_PLUGINS as $handle => $config) {
            $plugin = Craft::$app->plugins->getPlugin($handle);
            if ($plugin !== null) {
                $installed[$handle] = $config;
            }
        }

        return $installed;
    }

    /**
     * Get all available forms from all installed form plugins
     */
    public function getAllAvailableForms(): array
    {
        $forms = [];

        // Freeform
        $freeform = Craft::$app->plugins->getPlugin('freeform');
        if ($freeform !== null) {
            try {
                // Freeform 5.x uses FormsService with getAllForms()
                $freeformForms = $freeform->forms->getAllForms(true);
                foreach ($freeformForms as $form) {
                    $forms[] = [
                        'id' => $form->getId(),
                        'handle' => $form->getHandle(),
                        'name' => $form->getName(),
                        'plugin' => 'freeform',
                        'pluginName' => 'Freeform',
                    ];
                }
            } catch (\Throwable $e) {
                Craft::warning('Failed to get Freeform forms: ' . $e->getMessage(), __METHOD__);
            }
        }

        // Formie
        $formie = Craft::$app->plugins->getPlugin('formie');
        if ($formie !== null) {
            try {
                // Formie uses getForms() service
                $formieForms = $formie->getForms()->getAllForms();
                foreach ($formieForms as $form) {
                    $forms[] = [
                        'id' => $form->id,
                        'handle' => $form->handle,
                        'name' => $form->title,
                        'plugin' => 'formie',
                        'pluginName' => 'Formie',
                    ];
                }
            } catch (\Throwable $e) {
                Craft::warning('Failed to get Formie forms: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $forms;
    }

    /**
     * Build a CSS selector for a specific form
     */
    public function buildFormSelector(string $plugin, string $handle): string
    {
        $config = self::FORM_PLUGINS[$plugin] ?? null;
        if (!$config) {
            return '';
        }

        return str_replace('{handle}', $handle, $config['formAttributeSelector']);
    }

    /**
     * Get the generic form selector for a plugin (matches all forms from that plugin)
     */
    public function getGenericFormSelector(string $plugin): string
    {
        return self::FORM_PLUGINS[$plugin]['formSelector'] ?? '';
    }

    /**
     * Get success configuration for a plugin
     */
    public function getSuccessConfig(string $plugin): array
    {
        return self::FORM_PLUGINS[$plugin] ?? [];
    }

    /**
     * Get all supported success selectors for a plugin
     */
    public function getSuccessSelectors(string $plugin): array
    {
        return self::FORM_PLUGINS[$plugin]['successSelectors'] ?? [];
    }

    /**
     * Get the success event name for a plugin
     */
    public function getSuccessEvent(string $plugin): string
    {
        return self::FORM_PLUGINS[$plugin]['successEvent'] ?? '';
    }

    /**
     * Check if any form plugin is installed
     */
    public function hasFormPlugin(): bool
    {
        return !empty($this->getInstalledFormPlugins());
    }

    /**
     * Get plugin configuration by handle
     */
    public function getPluginConfig(string $plugin): ?array
    {
        if (!isset(self::FORM_PLUGINS[$plugin])) {
            return null;
        }

        // Only return config if plugin is actually installed
        $installedPlugin = Craft::$app->plugins->getPlugin($plugin);
        if ($installedPlugin === null) {
            return null;
        }

        return self::FORM_PLUGINS[$plugin];
    }
}
