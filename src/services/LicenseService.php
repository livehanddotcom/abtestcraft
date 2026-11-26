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
     * Memoized for web requests only (getPluginInfo() does a fair amount of
     * work); long-running console processes such as queue workers re-read it
     * so a purchased or revoked license is picked up.
     */
    private ?LicenseKeyStatus $status = null;

    /**
     * Get the current license key status
     *
     * Craft 5 keeps license status in its cached Plugin Store license info,
     * exposed through Plugins::getPluginInfo(). Don't use
     * Plugins::getPluginLicenseKeyStatus(): it reads a `licenseKeyStatus`
     * column the `plugins` table no longer has, so it always returns Unknown.
     */
    public function getStatus(): LicenseKeyStatus
    {
        if ($this->status !== null) {
            return $this->status;
        }

        try {
            $info = Craft::$app->getPlugins()->getPluginInfo(self::PLUGIN_HANDLE);
            $status = LicenseKeyStatus::tryFrom((string) ($info['licenseKeyStatus'] ?? ''))
                ?? LicenseKeyStatus::Unknown;
        } catch (\Throwable $e) {
            Craft::warning('ABTestCraft: Could not read license status: ' . $e->getMessage(), __METHOD__);
            $status = LicenseKeyStatus::Unknown;
        }

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->status = $status;
        }

        return $status;
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
     *
     * Trial installs are fully functional so people can evaluate the plugin
     * (e.g. on staging); Craft's own trial alerts plus ours prompt purchase.
     * Unknown is allowed during development (not yet connected to Plugin Store).
     * Invalid, Mismatched and Astray licenses are blocked.
     */
    public function canCreateTests(): bool
    {
        $status = $this->getStatus();
        return in_array($status, [
            LicenseKeyStatus::Valid,
            LicenseKeyStatus::Trial,
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
            LicenseKeyStatus::Trial => Craft::t(
                'abtestcraft',
                'You are using ABTestCraft in trial mode. Purchase a license to remove this message.'
            ),
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
