<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\services\TestsService;

/**
 * Regression tests for #1 / #5
 *
 * TestsService uses strict_types, and IDs often arrive as strings (request
 * params, DB columns). Its ID-taking methods must accept strings, otherwise
 * callers hit "must be of type int, string given". Calling these methods
 * needs a database, so this checks the signatures directly.
 */
class TestsServiceSignatureTest extends Unit
{
    /**
     * @return array<string, array{string, string}>
     */
    public function idParameterProvider(): array
    {
        return [
            'getTestById $id' => ['getTestById', 'id'],
            'getTestByControlEntryId $entryId' => ['getTestByControlEntryId', 'entryId'],
            'getTestByControlEntryId $siteId' => ['getTestByControlEntryId', 'siteId'],
            'getAllTests $siteId' => ['getAllTests', 'siteId'],
            'getTotalTestCount $siteId' => ['getTotalTestCount', 'siteId'],
            'getActiveTests $siteId' => ['getActiveTests', 'siteId'],
            'getTestsByStatus $siteId' => ['getTestsByStatus', 'siteId'],
            'getTestCounts $siteId' => ['getTestCounts', 'siteId'],
        ];
    }

    /**
     * @dataProvider idParameterProvider
     */
    public function testIdParameterAcceptsStrings(string $method, string $param): void
    {
        $parameter = null;
        foreach ((new \ReflectionMethod(TestsService::class, $method))->getParameters() as $p) {
            if ($p->getName() === $param) {
                $parameter = $p;
            }
        }

        $this->assertNotNull($parameter, "{$method}() has no \${$param} parameter");

        $type = $parameter->getType();
        $typeNames = $type instanceof \ReflectionUnionType
            ? array_map(fn(\ReflectionNamedType $t) => $t->getName(), $type->getTypes())
            : [$type?->getName()];

        $this->assertContains('int', $typeNames, "{$method}(\${$param}) should accept int");
        $this->assertContains('string', $typeNames, "{$method}(\${$param}) should accept string");
    }
}
