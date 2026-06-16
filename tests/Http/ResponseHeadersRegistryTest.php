<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Http;

use haddowg\JsonApiBundle\Http\ResponseHeaderOperation;
use haddowg\JsonApiBundle\Http\ResponseHeadersRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the {@see ResponseHeadersRegistry} resolution (bundle ADR 0054):
 * the per-type cache/deprecation layered over the global defaults, the
 * per-operation cache override, and the null result when nothing applies.
 */
final class ResponseHeadersRegistryTest extends TestCase
{
    #[Test]
    public function theGlobalDefaultAppliesToATypeThatDeclaresNothing(): void
    {
        $registry = new ResponseHeadersRegistry(
            byType: [],
            defaultCache: ['max_age' => 120],
            defaultDeprecation: ['deprecation' => true],
        );

        self::assertSame(120, $registry->cacheFor('widgets', ResponseHeaderOperation::Read)?->maxAge);
        self::assertTrue($registry->deprecationFor('widgets')?->deprecation);
    }

    #[Test]
    public function aResourceLevelCacheOverridesTheDefault(): void
    {
        $registry = new ResponseHeadersRegistry(
            byType: ['widgets' => ['cache' => ['max_age' => 60]]],
            defaultCache: ['max_age' => 120, 's_maxage' => 600],
        );

        $cache = $registry->cacheFor('widgets', ResponseHeaderOperation::Read);
        self::assertNotNull($cache);

        // Own max_age wins; the default s_maxage is inherited.
        self::assertSame(60, $cache->maxAge);
        self::assertSame(600, $cache->sharedMaxAge);
    }

    #[Test]
    public function aPerOperationOverrideLayersOverTheResourceLevel(): void
    {
        $registry = new ResponseHeadersRegistry(
            byType: [
                'widgets' => [
                    'cache' => ['max_age' => 60, 'public' => true],
                    'cache_operations' => ['collection' => ['max_age' => 30]],
                ],
            ],
        );

        $collection = $registry->cacheFor('widgets', ResponseHeaderOperation::Collection);
        self::assertNotNull($collection);

        // The collection override shortens max_age; the read uses the resource level.
        self::assertSame(30, $collection->maxAge);
        self::assertSame(60, $registry->cacheFor('widgets', ResponseHeaderOperation::Read)?->maxAge);
        // The override does not touch `public`, so it is still inherited on the collection.
        self::assertTrue($collection->public);
    }

    #[Test]
    public function noConfigAndNoDefaultResolvesToNull(): void
    {
        $registry = new ResponseHeadersRegistry();

        self::assertNull($registry->cacheFor('widgets', ResponseHeaderOperation::Read));
        self::assertNull($registry->deprecationFor('widgets'));
    }
}
