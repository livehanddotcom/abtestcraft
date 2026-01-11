<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\enums\LicenseKeyStatus;

/**
 * License service - checks plugin license status
 */
class LicenseService extends Component
{
    private const PLUGIN_HANDLE = 'abtestcraft';

    /**
     * Get the current license key status
     */
    public function getStatus(): LicenseKeyStatus
    {
        return Craft::$app->plugins->getPluginLicenseKeyStatus(self::PLUGIN_HANDLE);
    }

    /**
     * Check if the license is valid (not trial, invalid, etc.)
     */
    public function isValid(): bool
    {
        return $this->getStatus() === LicenseKeyStatus::Valid;
    }

    /**
     * Check if the plugin is running in trial mode
     */
    public function isTrial(): bool
    {
        return $this->getStatus() === LicenseKeyStatus::Trial;
    }

    /**
     * Check if there are license issues requiring attention
     */
    public function hasIssues(): bool
    {
        return in_array($this->getStatus(), [
            LicenseKeyStatus::Invalid,
            LicenseKeyStatus::Mismatched,
            LicenseKeyStatus::Astray,
        ], true);
    }

    /**
     * Check if creating new tests is allowed
     * Only Valid licenses can create new tests
     * Unknown is allowed during development (not yet connected to Plugin Store)
     */
    public function canCreateTests(): bool
    {
        $status = $this->getStatus();
        return in_array($status, [
            LicenseKeyStatus::Valid,
            LicenseKeyStatus::Unknown, // Allow during development
        ], true);
    }

    /**
     * Get human-readable status label
     */
    public function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            LicenseKeyStatus::Valid => Craft::t('abtestcraft', 'Licensed'),
            LicenseKeyStatus::Trial => Craft::t('abtestcraft', 'Trial'),
            LicenseKeyStatus::Invalid => Craft::t('abtestcraft', 'Invalid License'),
            LicenseKeyStatus::Mismatched => Craft::t('abtestcraft', 'License Mismatched'),
            LicenseKeyStatus::Astray => Craft::t('abtestcraft', 'License Not Allowed'),
            LicenseKeyStatus::Unknown => Craft::t('abtestcraft', 'Unknown'),
        };
    }

    /**
     * Get status color class for UI display
     */
    public function getStatusColor(): string
    {
        return match ($this->getStatus()) {
            LicenseKeyStatus::Valid => 'green',
            LicenseKeyStatus::Trial => 'orange',
            LicenseKeyStatus::Invalid, LicenseKeyStatus::Mismatched, LicenseKeyStatus::Astray => 'red',
            LicenseKeyStatus::Unknown => 'gray',
        };
    }

    /**
     * Get detailed status information for display
     *
     * @return array{status: LicenseKeyStatus, label: string, color: string, message: string|null}
     */
    public function getStatusInfo(): array
    {
        $status = $this->getStatus();

        $message = match ($status) {
            LicenseKeyStatus::Trial => Craft::t('abtestcraft', 'You are using ABTestCraft in trial mode. Purchase a license to remove this message.'),
            LicenseKeyStatus::Invalid => Craft::t('abtestcraft', 'Your license key is invalid.'),
            LicenseKeyStatus::Mismatched => Craft::t('abtestcraft', 'This license is tied to another Craft install.'),
            LicenseKeyStatus::Astray => Craft::t('abtestcraft', 'This license does not cover this version.'),
            default => null,
        };

        return [
            'status' => $status,
            'label' => $this->getStatusLabel(),
            'color' => $this->getStatusColor(),
            'message' => $message,
        ];
    }
}
