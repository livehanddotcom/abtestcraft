<?php

declare(strict_types=1);

namespace livehand\abtestcraft\models;

use craft\base\Model;
use DateTime;

/**
 * Goal model - represents a conversion goal for a split test
 */
class Goal extends Model
{
    public ?int $id = null;
    public ?int $testId = null;
    public string $goalType = 'form';
    public bool $isEnabled = true;
    public ?array $config = null;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    // Goal type constants
    public const TYPE_FORM = 'form';
    public const TYPE_PHONE = 'phone';
    public const TYPE_EMAIL = 'email';
    public const TYPE_DOWNLOAD = 'download';
    public const TYPE_PAGE = 'page';
    public const TYPE_CUSTOM = 'custom';

    // Form success detection methods
    public const SUCCESS_REDIRECT = 'redirect';
    public const SUCCESS_ELEMENT = 'element';
    public const SUCCESS_ANY = 'any';

    // Form configuration modes
    public const MODE_SMART = 'smart';
    public const MODE_ADVANCED = 'advanced';

    // Page match types
    public const MATCH_EXACT = 'exact';
    public const MATCH_STARTS_WITH = 'startsWith';
    public const MATCH_CONTAINS = 'contains';

    public function defineRules(): array
    {
        return [
            [['testId', 'goalType'], 'required'],
            [['goalType'], 'in', 'range' => [
                self::TYPE_FORM,
                self::TYPE_PHONE,
                self::TYPE_EMAIL,
                self::TYPE_DOWNLOAD,
                self::TYPE_PAGE,
                self::TYPE_CUSTOM,
            ]],
            [['isEnabled'], 'boolean'],
        ];
    }

    /**
     * Get goal type options for UI
     */
    public static function getGoalTypeOptions(): array
    {
        return [
            self::TYPE_FORM => [
                'label' => 'Form Submissions',
                'description' => 'Track specific form submissions (contact forms, lead forms, etc.)',
                'hasConfig' => true,
            ],
            self::TYPE_PHONE => [
                'label' => 'Phone Clicks',
                'description' => 'Automatically track clicks on tel: links',
                'hasConfig' => false,
            ],
            self::TYPE_EMAIL => [
                'label' => 'Email Clicks',
                'description' => 'Automatically track clicks on mailto: links',
                'hasConfig' => false,
            ],
            self::TYPE_DOWNLOAD => [
                'label' => 'File Downloads',
                'description' => 'Track downloads of specific file types',
                'hasConfig' => true,
            ],
            self::TYPE_PAGE => [
                'label' => 'Page Visit',
                'description' => 'Track visits to a specific URL',
                'hasConfig' => true,
            ],
            self::TYPE_CUSTOM => [
                'label' => 'Custom Event',
                'description' => 'Track custom JavaScript events',
                'hasConfig' => true,
            ],
        ];
    }

    /**
     * Get the form configuration mode (smart or advanced)
     */
    public function getFormMode(): string
    {
        return $this->config['mode'] ?? self::MODE_ADVANCED;
    }

    /**
     * Check if using smart form detection mode
     */
    public function isSmartMode(): bool
    {
        return $this->getFormMode() === self::MODE_SMART;
    }

    /**
     * Get the plugin handle for smart mode (e.g., 'freeform', 'formie')
     */
    public function getPluginHandle(): ?string
    {
        return $this->config['pluginHandle'] ?? null;
    }

    /**
     * Get the form handle for smart mode (single form, legacy)
     */
    public function getFormHandle(): ?string
    {
        return $this->config['formHandle'] ?? null;
    }

    /**
     * Get selected forms for smart mode (array of 'plugin:handle' strings)
     */
    public function getSelectedForms(): array
    {
        return $this->config['forms'] ?? [];
    }

    /**
     * Get the form selector from config
     */
    public function getFormSelector(): ?string
    {
        return $this->config['formSelector'] ?? null;
    }

    /**
     * Get the success detection method from config
     */
    public function getSuccessMethod(): string
    {
        return $this->config['successMethod'] ?? self::SUCCESS_ANY;
    }

    /**
     * Get the success selector (URL for redirect, CSS selector for element)
     */
    public function getSuccessSelector(): ?string
    {
        return $this->config['successSelector'] ?? null;
    }

    /**
     * Get file extensions for download tracking
     */
    public function getFileExtensions(): array
    {
        $extensions = $this->config['extensions'] ?? ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
        if (is_string($extensions)) {
            $extensions = array_map('trim', explode(',', $extensions));
        }
        return $extensions;
    }

    /**
     * Get page URL for page visit tracking
     */
    public function getPageUrl(): ?string
    {
        return $this->config['pageUrl'] ?? null;
    }

    /**
     * Get page match type
     */
    public function getMatchType(): string
    {
        return $this->config['matchType'] ?? self::MATCH_EXACT;
    }

    /**
     * Get custom event name (single, for backward compatibility)
     */
    public function getEventName(): ?string
    {
        return $this->config['eventName'] ?? null;
    }

    /**
     * Get custom event names as array (supports comma-separated)
     */
    public function getEventNames(): array
    {
        $eventName = $this->config['eventName'] ?? '';
        if (empty($eventName)) {
            return [];
        }
        return array_map('trim', explode(',', $eventName));
    }

    /**
     * Set form goal configuration
     */
    public function setFormConfig(string $formSelector, string $successMethod, ?string $successSelector = null): void
    {
        $this->config = [
            'formSelector' => $formSelector,
            'successMethod' => $successMethod,
            'successSelector' => $successSelector,
        ];
    }

    /**
     * Set download goal configuration
     */
    public function setDownloadConfig(array $extensions): void
    {
        $this->config = [
            'extensions' => $extensions,
        ];
    }

    /**
     * Set page visit goal configuration
     */
    public function setPageConfig(string $pageUrl, string $matchType = self::MATCH_EXACT): void
    {
        $this->config = [
            'pageUrl' => $pageUrl,
            'matchType' => $matchType,
        ];
    }

    /**
     * Set custom event goal configuration
     */
    public function setCustomConfig(string $eventName): void
    {
        $this->config = [
            'eventName' => $eventName,
        ];
    }

    /**
     * Convert to array for JavaScript config
     */
    public function toJsConfig(): array
    {
        $config = $this->config ?? [];

        // For custom events, add eventNames array for JavaScript to use
        if ($this->goalType === self::TYPE_CUSTOM && !empty($config['eventName'])) {
            $config['eventNames'] = $this->getEventNames();
        }

        // For form goals in smart mode, build parsedForms array for JavaScript
        if ($this->goalType === self::TYPE_FORM && $this->isSmartMode()) {
            $config['mode'] = self::MODE_SMART;

            // Parse the forms array into structured format for JS
            $parsedForms = [];
            foreach ($this->getSelectedForms() as $formId) {
                if (!empty($formId) && str_contains($formId, ':')) {
                    [$pluginHandle, $formHandle] = explode(':', $formId, 2);
                    $parsedForms[] = [
                        'plugin' => $pluginHandle,
                        'handle' => $formHandle,
                    ];
                }
            }
            $config['parsedForms'] = $parsedForms;

            // Legacy single form fields (for backward compatibility)
            $config['pluginHandle'] = $this->getPluginHandle();
            $config['formHandle'] = $this->getFormHandle();
        }

        return [
            'id' => $this->id,
            'type' => $this->goalType,
            'enabled' => $this->isEnabled,
            'config' => $config,
        ];
    }
}
