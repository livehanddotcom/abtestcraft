<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\services\AssignmentService;
use livehand\abtestcraft\ABTestCraft;

/**
 * Integration tests for AssignmentService
 *
 * These tests require full Craft context because validateCookieValue
 * uses Craft::warning() for logging.
 */
class AssignmentServiceIntegrationTest extends Unit
{
    private AssignmentService $service;

    protected function _before(): void
    {
        $this->service = ABTestCraft::getInstance()->assignment;
    }

    /**
     * Test cookie value validation for visitor ID
     *
     * The validateCookieValue method should:
     * - Accept valid UUID format for visitor ID cookies
     * - Reject invalid formats and log a warning
     */
    public function testValidateCookieValueForVisitorId(): void
    {
        $method = new \ReflectionMethod(AssignmentService::class, 'validateCookieValue');
        $method->setAccessible(true);

        // Valid UUID should pass
        $validUuid = '12345678-1234-1234-1234-123456789abc';
        $result = $method->invoke($this->service, '_abtestcraft_vid', $validUuid);
        $this->assertEquals($validUuid, $result, 'Valid UUID should be accepted');

        // Invalid UUID should return null
        $invalidUuid = 'not-a-valid-uuid';
        $result = $method->invoke($this->service, '_abtestcraft_vid', $invalidUuid);
        $this->assertNull($result, 'Invalid UUID should be rejected');

        // UUID with wrong case should still work (case insensitive)
        $upperCaseUuid = '12345678-ABCD-EFAB-CDEF-123456789ABC';
        $result = $method->invoke($this->service, '_abtestcraft_vid', $upperCaseUuid);
        $this->assertEquals($upperCaseUuid, $result, 'Uppercase UUID should be accepted');

        // Malicious input should be rejected
        $maliciousInput = '<script>alert("xss")</script>';
        $result = $method->invoke($this->service, '_abtestcraft_vid', $maliciousInput);
        $this->assertNull($result, 'Malicious input should be rejected');

        // SQL injection attempt should be rejected
        $sqlInjection = "'; DROP TABLE users; --";
        $result = $method->invoke($this->service, '_abtestcraft_vid', $sqlInjection);
        $this->assertNull($result, 'SQL injection attempt should be rejected');
    }

    /**
     * Test cookie value validation for variant cookies
     *
     * The validateCookieValue method should:
     * - Accept 'control' or 'variant' for variant cookies
     * - Reject any other values and log a warning
     */
    public function testValidateCookieValueForVariant(): void
    {
        $method = new \ReflectionMethod(AssignmentService::class, 'validateCookieValue');
        $method->setAccessible(true);

        // Valid 'control' value should pass
        $result = $method->invoke($this->service, '_abtestcraft_1_testhandle', Test::VARIANT_CONTROL);
        $this->assertEquals(Test::VARIANT_CONTROL, $result, "'control' should be accepted");

        // Valid 'variant' value should pass
        $result = $method->invoke($this->service, '_abtestcraft_1_testhandle', Test::VARIANT_VARIANT);
        $this->assertEquals(Test::VARIANT_VARIANT, $result, "'variant' should be accepted");

        // Invalid variant value should return null
        $result = $method->invoke($this->service, '_abtestcraft_1_testhandle', 'invalid');
        $this->assertNull($result, 'Invalid variant value should be rejected');

        // Malicious input should be rejected
        $result = $method->invoke($this->service, '_abtestcraft_1_testhandle', '<script>alert(1)</script>');
        $this->assertNull($result, 'Malicious input in variant cookie should be rejected');

        // Empty string should be rejected
        $result = $method->invoke($this->service, '_abtestcraft_1_testhandle', '');
        $this->assertNull($result, 'Empty string should be rejected');
    }
}
