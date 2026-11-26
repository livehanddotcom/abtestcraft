<?php

declare(strict_types=1);

namespace livehand\abtestcraft\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use DateTime;
use livehand\abtestcraft\ABTestCraft;

/**
 * Test model
 */
class Test extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;
    public string $name = '';
    public string $handle = '';
    public ?string $hypothesis = null;
    public ?string $variantDescription = null;
    public ?string $learnings = null;
    public string $status = 'draft';
    public ?int $controlEntryId = null;
    public ?int $variantEntryId = null;
    public int $trafficSplit = 50;
    public string $goalType = 'form';
    public ?string $goalValue = null;

    /** @var string|null The goal results are judged on; null means any goal */
    public ?string $primaryGoalType = null;
    public ?DateTime $startedAt = null;
    public ?DateTime $endedAt = null;
    public ?string $winnerVariant = null;
    public ?DateTime $significanceNotifiedAt = null;

    /** @var DateTime|null Start the test automatically at this time */
    public ?DateTime $scheduledStartAt = null;

    /** @var DateTime|null End the test automatically at this time */
    public ?DateTime $scheduledEndAt = null;

    /** @var bool Completed with the variant as winner: show it to everyone */
    public bool $serveWinner = false;

    /** @var string What happens when a winner is found (WINNER_* constants) */
    public string $whenWinnerFound = self::WINNER_NOTIFY;

    /** @var array|null Safety guardrail settings (see getGuardrail()) */
    public ?array $guardrail = null;

    public ?DateTime $guardrailTriggeredAt = null;
    public ?string $guardrailMessage = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?DateTime $dateDeleted = null;
    public ?string $uid = null;

    // When a winner is found
    public const WINNER_NOTIFY = 'notify';
    public const WINNER_END = 'end';
    public const WINNER_SERVE = 'serve';

    // Guardrail actions
    public const GUARDRAIL_PAUSE = 'pause';
    public const GUARDRAIL_END = 'end';

    /** Guardrail strictness options (confidence levels) */
    public const GUARDRAIL_CONFIDENCE_LEVELS = [0.995, 0.99, 0.975, 0.95];

    /** Guardrail defaults: built to avoid false alarms */
    public const GUARDRAIL_DEFAULTS = [
        'enabled' => false,
        'drop' => 25,
        'goalType' => null,
        'confidence' => 0.99,
        'minVisitors' => 250,
        'minConversions' => 25,
        'action' => self::GUARDRAIL_PAUSE,
    ];

    // Status constants
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    /**
     * Valid test handle: starts with a lowercase letter; letters and digits,
     * optionally hyphen-separated. Accepts kebab-case (`homepage-hero`) and
     * camelCase (`homepageHero`, what the CP's Craft.HandleGenerator produces).
     * Shared with the tracking endpoint so the two can't drift apart.
     */
    public const HANDLE_PATTERN = '/^[a-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*$/';

    // Variant constants
    public const VARIANT_CONTROL = 'control';
    public const VARIANT_VARIANT = 'variant';

    // Goal type constants
    public const GOAL_PHONE = 'phone';
    public const GOAL_FORM = 'form';
    public const GOAL_PAGE = 'page';
    public const GOAL_EMAIL = 'email';
    public const GOAL_DOWNLOAD = 'download';

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'controlEntryId', 'variantEntryId', 'goalType'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['trafficSplit'], 'integer', 'min' => 0, 'max' => 100],
            [
                ['status'],
                'in',
                'range' => [self::STATUS_DRAFT, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_COMPLETED],
            ],
            [
                ['goalType'],
                'in',
                'range' => [self::GOAL_PHONE, self::GOAL_FORM, self::GOAL_PAGE, self::GOAL_EMAIL, self::GOAL_DOWNLOAD],
            ],
            [['goalValue'], 'string', 'max' => 500],
            [['primaryGoalType'], 'in', 'range' => array_keys(Goal::getGoalTypeOptions()), 'skipOnEmpty' => true],
            [['winnerVariant'], 'in', 'range' => [self::VARIANT_CONTROL, self::VARIANT_VARIANT, null]],
            [['whenWinnerFound'], 'in', 'range' => [self::WINNER_NOTIFY, self::WINNER_END, self::WINNER_SERVE]],
            [['serveWinner'], 'boolean'],
            [['scheduledEndAt'], 'validateSchedule'],
            [['guardrail'], 'validateGuardrail'],
            [
                ['handle'],
                'match',
                'pattern' => self::HANDLE_PATTERN,
                'message' => 'Handle must start with a lowercase letter and contain only letters, numbers, ' .
                    'and hyphens (e.g. homepageHero or homepage-hero).',
            ],
            // Prevent same entry for control and variant
            [
                ['variantEntryId'],
                'compare',
                'compareAttribute' => 'controlEntryId',
                'operator' => '!=',
                'message' => 'Control and variant entries must be different',
            ],
            // Prevent circular reference - variant cannot be a descendant of control
            [['variantEntryId'], 'validateNotDescendantOfControl'],
        ];
    }

    /**
     * Validate that the variant entry is not a descendant of the control entry
     * Prevents circular references that could cause infinite loops
     */
    public function validateNotDescendantOfControl(string $attribute): void
    {
        if (!$this->controlEntryId || !$this->variantEntryId) {
            return;
        }

        // Check if variant is a descendant of control
        $isDescendant = Entry::find()
            ->descendantOf($this->controlEntryId)
            ->id($this->variantEntryId)
            ->siteId($this->siteId)
            ->status(null) // variants are often disabled
            ->exists();

        if ($isDescendant) {
            $this->addError(
                $attribute,
                'The variant entry cannot be a child/descendant of the control entry. '
                . 'This would create a circular reference.'
            );
        }
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Test Name',
            'handle' => 'Handle',
            'hypothesis' => 'Hypothesis',
            'variantDescription' => 'Variant Changes',
            'learnings' => 'Learnings',
            'controlEntryId' => 'Control Entry (Original)',
            'variantEntryId' => 'Variant Entry (Test)',
            'trafficSplit' => 'Traffic to Variant (%)',
            'goalType' => 'Goal Type',
            'goalValue' => 'Goal Value',
            'primaryGoalType' => 'Primary Goal',
        ];
    }

    /**
     * Generate a handle from the name
     */
    public function generateHandle(): void
    {
        if (empty($this->handle) && !empty($this->name)) {
            $this->handle = StringHelper::toKebabCase($this->name);
        }
    }

    /**
     * Get the control entry
     */
    public function getControlEntry(): ?Entry
    {
        if (!$this->controlEntryId) {
            return null;
        }
        // Any status: tested entries are often disabled or scheduled
        return Entry::find()->id($this->controlEntryId)->siteId($this->siteId)->status(null)->one();
    }

    /**
     * Get the variant entry
     */
    public function getVariantEntry(): ?Entry
    {
        if (!$this->variantEntryId) {
            return null;
        }
        // Any status: a variant is usually kept disabled so it isn't public at
        // its own URL; it's only ever shown through the test
        return Entry::find()->id($this->variantEntryId)->siteId($this->siteId)->status(null)->one();
    }

    /**
     * Check if test is running
     */
    public function isRunning(): bool
    {
        // A trashed test is inert even if its status is still "running":
        // no variant swapping, tracking, SEO tags or page-cache exclusion
        return $this->status === self::STATUS_RUNNING && $this->dateDeleted === null;
    }

    /**
     * Check if test is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if test has been soft deleted
     */
    public function isTrashed(): bool
    {
        return $this->dateDeleted !== null;
    }

    /**
     * Get the duration of the test in days
     * Returns null if test hasn't started or is still running
     */
    public function getDurationDays(): ?int
    {
        if (!$this->startedAt) {
            return null;
        }

        $endDate = $this->endedAt ?? new DateTime();
        $diff = $this->startedAt->diff($endDate);

        // Return at least 1 day if test ran for any amount of time
        return max(1, $diff->days);
    }

    /**
     * Check if test can be started (status only)
     */
    public function canStart(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PAUSED]);
    }

    /**
     * Check if test has enabled goals configured
     */
    public function hasEnabledGoals(): bool
    {
        return !empty($this->getEnabledGoals());
    }

    /**
     * The end date must come after the start date
     */
    public function validateSchedule(string $attribute): void
    {
        if ($this->scheduledStartAt && $this->scheduledEndAt && $this->scheduledEndAt <= $this->scheduledStartAt) {
            $this->addError($attribute, 'The end date must be after the start date.');
        }
    }

    /**
     * Guardrail settings within safe ranges
     */
    public function validateGuardrail(string $attribute): void
    {
        $guardrail = $this->getGuardrail();
        if (!$guardrail['enabled']) {
            return;
        }

        if ($guardrail['drop'] < 5 || $guardrail['drop'] > 90) {
            $this->addError($attribute, 'The guardrail drop must be between 5% and 90%.');
        }
        if (!in_array($guardrail['confidence'], self::GUARDRAIL_CONFIDENCE_LEVELS, true)) {
            $this->addError($attribute, 'Choose one of the guardrail strictness levels.');
        }
        if ($guardrail['minVisitors'] < 100) {
            $this->addError($attribute, 'The guardrail needs at least 100 visitors per version before it can act.');
        }
        if ($guardrail['minConversions'] < 10) {
            $this->addError($attribute, 'The guardrail needs at least 10 control conversions before it can act.');
        }
        if (!in_array($guardrail['action'], [self::GUARDRAIL_PAUSE, self::GUARDRAIL_END], true)) {
            $this->addError($attribute, 'Choose what the guardrail does when it triggers.');
        }
        if ($guardrail['goalType'] !== null && !array_key_exists($guardrail['goalType'], Goal::getGoalTypeOptions())) {
            $this->addError($attribute, 'Choose a valid goal for the guardrail.');
        }
    }

    /**
     * Guardrail settings with defaults filled in, and typed
     *
     * @return array{enabled: bool, drop: int, goalType: ?string, confidence: float, minVisitors: int,
     *     minConversions: int, action: string}
     */
    public function getGuardrail(): array
    {
        $values = array_merge(self::GUARDRAIL_DEFAULTS, array_filter(
            $this->guardrail ?? [],
            fn($value) => $value !== null && $value !== ''
        ));

        return [
            'enabled' => (bool) $values['enabled'],
            'drop' => (int) $values['drop'],
            'goalType' => $values['goalType'] ? (string) $values['goalType'] : null,
            'confidence' => (float) $values['confidence'],
            'minVisitors' => (int) $values['minVisitors'],
            'minConversions' => (int) $values['minConversions'],
            'action' => (string) $values['action'],
        ];
    }

    /**
     * The goal the guardrail watches: its own choice, or the primary goal
     */
    public function getGuardrailGoalType(): ?string
    {
        return $this->getGuardrail()['goalType'] ?? $this->primaryGoalType;
    }

    /**
     * Whether a completed test is showing its variant to everyone
     */
    public function isServingWinner(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->serveWinner
            && $this->winnerVariant === self::VARIANT_VARIANT
            && $this->dateDeleted === null;
    }

    /**
     * Label of the goal results are judged on
     */
    public function getPrimaryGoalLabel(): string
    {
        if ($this->primaryGoalType === null) {
            return 'Any goal';
        }

        return Goal::getGoalTypeOptions()[$this->primaryGoalType]['label'] ?? ucfirst($this->primaryGoalType);
    }

    /**
     * Check if test is ready to start (status + goals)
     */
    public function isReadyToStart(): bool
    {
        return $this->canStart() && $this->hasEnabledGoals();
    }

    /**
     * Check if test can be paused
     */
    public function canPause(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Get available goal types
     */
    public static function getGoalTypes(): array
    {
        return [
            self::GOAL_PHONE => 'Phone Click (tel: links)',
            self::GOAL_FORM => 'Form Submission',
            self::GOAL_PAGE => 'Page Visit',
            self::GOAL_EMAIL => 'Email Click (mailto: links)',
            self::GOAL_DOWNLOAD => 'File Download',
        ];
    }

    /**
     * Get available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_COMPLETED => 'Completed',
        ];
    }

    /**
     * Get all goals for this test
     *
     * @return Goal[]
     */
    public function getGoals(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getGoalsByTestId($this->id);
    }

    /**
     * Get enabled goals for this test
     *
     * @return Goal[]
     */
    public function getEnabledGoals(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getEnabledGoalsByTestId($this->id);
    }

    /**
     * Check if test has any goals configured
     */
    public function hasGoals(): bool
    {
        return !empty($this->getGoals());
    }

    /**
     * Get goals configuration for JavaScript tracking
     */
    public function getGoalsJsConfig(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getGoalsJsConfig($this->id);
    }
}
