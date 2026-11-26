<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use craft\enums\LicenseKeyStatus;
use livehand\abtestcraft\services\LicenseService;

/**
 * Unit tests for LicenseService
 *
 * getStatus() is stubbed so license gating can be tested without Craft's
 * plugin service or a Plugin Store connection.
 */
class LicenseServiceTest extends Unit
{
    /**
     * Trial installs must be able to create tests so the plugin can be evaluated (#6)
     */
    public function testTrialCanCreateTests(): void
    {
        $this->assertTrue($this->serviceWithStatus(LicenseKeyStatus::Trial)->canCreateTests());
    }

    public function testValidCanCreateTests(): void
    {
        $this->assertTrue($this->serviceWithStatus(LicenseKeyStatus::Valid)->canCreateTests());
    }

    public function testUnknownCanCreateTests(): void
    {
        $this->assertTrue($this->serviceWithStatus(LicenseKeyStatus::Unknown)->canCreateTests());
    }

    /**
     * License problems still block creating tests
     */
    public function testLicenseIssuesCannotCreateTests(): void
    {
        foreach ([LicenseKeyStatus::Invalid, LicenseKeyStatus::Mismatched, LicenseKeyStatus::Astray] as $status) {
            $this->assertFalse(
                $this->serviceWithStatus($status)->canCreateTests(),
                "Status {$status->value} should not be able to create tests"
            );
        }
    }

    public function testIsTrialAndHasIssues(): void
    {
        $trial = $this->serviceWithStatus(LicenseKeyStatus::Trial);
        $this->assertTrue($trial->isTrial());
        $this->assertFalse($trial->hasIssues());

        $invalid = $this->serviceWithStatus(LicenseKeyStatus::Invalid);
        $this->assertFalse($invalid->isTrial());
        $this->assertTrue($invalid->hasIssues());
    }

    private function serviceWithStatus(LicenseKeyStatus $status): LicenseService
    {
        return new class ($status) extends LicenseService {
            public function __construct(private LicenseKeyStatus $stubStatus)
            {
                parent::__construct();
            }

            public function getStatus(): LicenseKeyStatus
            {
                return $this->stubStatus;
            }
        };
    }
}
