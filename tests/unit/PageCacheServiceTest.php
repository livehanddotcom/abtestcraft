<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\services\PageCacheService;

/**
 * Unit tests for PageCacheService
 *
 * getBlitz() is overridden so these don't depend on whether Blitz happens to
 * be installed: one variant simulates Blitz being absent, another supplies a
 * minimal fake of the Blitz services the plugin touches.
 */
class PageCacheServiceTest extends Unit
{
    public function testIsCacheGeneratorRequestIsFalseWithoutBlitz(): void
    {
        $this->assertFalse($this->serviceWithBlitz(null)->isCacheGeneratorRequest());
    }

    public function testIsCacheGeneratorRequestReflectsBlitz(): void
    {
        $this->assertTrue($this->serviceWithBlitz($this->fakeBlitz(isGenerator: true))->isCacheGeneratorRequest());
        $this->assertFalse($this->serviceWithBlitz($this->fakeBlitz(isGenerator: false))->isCacheGeneratorRequest());
    }

    /**
     * A Blitz error must never break page rendering
     */
    public function testIsCacheGeneratorRequestIsFalseWhenBlitzThrows(): void
    {
        $blitz = $this->fakeBlitz(isGenerator: true);
        $blitz->cacheRequest = new class () {
            public function getIsGeneratorRequest(): bool
            {
                throw new \RuntimeException('boom');
            }
        };

        $this->assertFalse($this->serviceWithBlitz($blitz)->isCacheGeneratorRequest());
    }

    public function testClearTestPagesIsNoOpWithoutBlitz(): void
    {
        // Would query entries if it didn't bail out before touching Craft
        $this->serviceWithBlitz(null)->clearTestPages($this->runningTest(controlEntryId: 123));
        $this->assertTrue(true);
    }

    public function testClearTestPagesSkipsTestsWithoutControlEntry(): void
    {
        $blitz = $this->fakeBlitz(isGenerator: false);

        $this->serviceWithBlitz($blitz)->clearTestPages($this->runningTest(controlEntryId: null));

        $this->assertSame([], $blitz->clearCache->calls);
        $this->assertSame([], $blitz->cachePurger->calls);
    }

    private function serviceWithBlitz(?object $blitz): PageCacheService
    {
        return new class ($blitz) extends PageCacheService {
            public function __construct(private ?object $fakeBlitz)
            {
                parent::__construct();
            }

            protected function getBlitz(): ?object
            {
                return $this->fakeBlitz;
            }
        };
    }

    /**
     * Minimal stand-in for the Blitz services PageCacheService uses
     */
    private function fakeBlitz(bool $isGenerator): object
    {
        $recorder = fn() => new class () {
            public array $calls = [];

            public function clearUris(array $siteUris): void
            {
                $this->calls[] = $siteUris;
            }

            public function purgeUris(array $siteUris): void
            {
                $this->calls[] = $siteUris;
            }
        };

        $blitz = new \stdClass();
        $blitz->clearCache = $recorder();
        $blitz->cachePurger = $recorder();
        $blitz->cacheRequest = new class ($isGenerator) {
            public function __construct(private bool $isGenerator)
            {
            }

            public function getIsGeneratorRequest(): bool
            {
                return $this->isGenerator;
            }
        };

        return $blitz;
    }

    private function runningTest(?int $controlEntryId): Test
    {
        $test = new Test();
        $test->handle = 'homepage-hero';
        $test->siteId = 1;
        $test->controlEntryId = $controlEntryId;
        $test->status = Test::STATUS_RUNNING;

        return $test;
    }

    /**
     * @return array<string, array{string, ?string, string[]}>
     */
    public function cachedUriBasesProvider(): array
    {
        return [
            'query string' => ['news?utm_source=x', 'p', ['news']],
            'page 2' => ['news/p2', 'p', ['news']],
            'page 12 with query' => ['news/p12?a=1', 'p', ['news/p12', 'news']],
            'similar slug is not a page' => ['news/press', 'p', []],
            'trigger without number' => ['news/p', 'p', []],
            'plain uri has no bases' => ['newsletter', 'p', []],
            'deeper path' => ['news/p2/extra', 'p', []],
            'nested page' => ['a/b/p3', 'p', ['a/b']],
            'homepage query' => ['?utm_source=x', 'p', ['']],
            'homepage page 3' => ['p3', 'p', ['']],
            'homepage other page' => ['press', 'p', []],
            'query-style trigger: no path pages' => ['news/p2', null, []],
            'longer trigger' => ['news/page4', 'page', ['news']],
        ];
    }

    /**
     * @dataProvider cachedUriBasesProvider
     * @param string[] $expected
     */
    public function testCachedUriBases(string $cachedUri, ?string $pageTrigger, array $expected): void
    {
        $this->assertSame($expected, PageCacheService::cachedUriBases($cachedUri, $pageTrigger));
    }
}
