<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Http;

use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResponseHeadersPass;
use haddowg\JsonApiBundle\Http\ResponseHeaderOperation;
use haddowg\JsonApiBundle\Http\ResponseHeadersRegistry;
use haddowg\JsonApiBundle\JsonApiBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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
    public function aServersDefaultsLayerBetweenTheResourceAndTheGlobalDefaults(): void
    {
        $registry = new ResponseHeadersRegistry(
            defaultCache: ['max_age' => 120, 'public' => true, 'vary' => ['Accept']],
            defaultDeprecation: ['deprecation' => true, 'sunset_link' => 'https://example.test/global'],
            servers: ['v5' => ['dated' => ['deprecation' => ['sunset' => 'Sat, 31 Dec 2050 23:59:59 GMT'], 'cache' => ['max_age' => 60]]]],
            serverDefaults: ['v5' => [
                'cache' => ['max_age' => 30, 'vary' => ['Accept-Language']],
                'deprecation' => ['sunset' => 'Sat, 01 May 2027 00:00:00 GMT'],
            ]],
        );

        // Field by field: the server's sunset, the global deprecation and link.
        $plain = $registry->deprecationFor('widgets', 'v5');
        self::assertNotNull($plain);
        self::assertTrue($plain->deprecation);
        self::assertSame('Sat, 01 May 2027 00:00:00 GMT', $plain->sunset);
        self::assertSame('https://example.test/global', $plain->sunsetLink);

        // The resource's own sunset beats the server's.
        self::assertSame('Sat, 31 Dec 2050 23:59:59 GMT', $registry->deprecationFor('dated', 'v5')?->sunset);

        // Cache keeps its merge: max_age replaced, public inherited, Vary unioned.
        $cache = $registry->cacheFor('widgets', ResponseHeaderOperation::Read, 'v5');
        self::assertNotNull($cache);
        self::assertSame(30, $cache->maxAge);
        self::assertTrue($cache->public);
        self::assertSame(['Accept', 'Accept-Language'], $cache->vary);
        self::assertSame(60, $registry->cacheFor('dated', ResponseHeaderOperation::Read, 'v5')?->maxAge);

        // Another server, and the default one, keep the global defaults.
        self::assertNull($registry->deprecationFor('widgets', 'v6')?->sunset);
        self::assertSame(120, $registry->cacheFor('widgets', ResponseHeaderOperation::Read)?->maxAge);
    }

    #[Test]
    public function noConfigAndNoDefaultResolvesToNull(): void
    {
        $registry = new ResponseHeadersRegistry();

        self::assertNull($registry->cacheFor('widgets', ResponseHeaderOperation::Read));
        self::assertNull($registry->deprecationFor('widgets'));
    }

    #[Test]
    public function eachServerHoldsOnlyWhatItsOwnClassDeclared(): void
    {
        $registry = new ResponseHeadersRegistry(
            byType: ['widgets' => ['cache' => ['max_age' => 10, 'private' => true]]],
            defaultCache: ['max_age' => 120],
            servers: [
                'v5' => ['widgets' => ['cache' => ['max_age' => 60, 'public' => true], 'deprecation' => ['deprecation' => true]]],
                'v6' => [],
            ],
        );

        self::assertSame(10, $registry->cacheFor('widgets', ResponseHeaderOperation::Read)?->maxAge);
        self::assertNull($registry->deprecationFor('widgets'));
        self::assertSame(60, $registry->cacheFor('widgets', ResponseHeaderOperation::Read, 'v5')?->maxAge);
        self::assertTrue($registry->deprecationFor('widgets', 'v5')?->deprecation);
        self::assertSame(120, $registry->cacheFor('widgets', ResponseHeaderOperation::Read, 'v6')?->maxAge);
    }

    #[Test]
    public function thePassRecordsAClassOnSeveralServersUnderEach(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ResponseHeadersRegistry::class, (new Definition(ResponseHeadersRegistry::class))->setArgument('$byType', []));
        $container->setDefinition('widgets', (new Definition(\stdClass::class))
            ->addTag(JsonApiBundle::RESOURCE_TAG, [
                'type' => 'widgets',
                'server' => 'default,v5',
                'response_headers' => '{"cache":{"max_age":60}}',
            ]));

        (new ResponseHeadersPass())->process($container);

        $definition = $container->getDefinition(ResponseHeadersRegistry::class);
        self::assertSame(['widgets' => ['cache' => ['max_age' => 60]]], $definition->getArgument('$byType'));
        self::assertSame(['v5' => ['widgets' => ['cache' => ['max_age' => 60]]]], $definition->getArgument('$servers'));
    }
}
