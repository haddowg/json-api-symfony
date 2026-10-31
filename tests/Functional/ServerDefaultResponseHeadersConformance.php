<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ResourcesOnServerPass;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ServerHeaderDefaults;
use PHPUnit\Framework\Attributes\Test;

/**
 * A named server's own `defaults` block (bundle ADR 0054) over global defaults of a public
 * 120s cache and `Deprecation: true`. The named server sets a sunset and its link and a
 * 30s cache varying on Accept-Language. On the named server `plainWidgets` declares
 * nothing, `cachedWidgets` caches for 60s and `deprecatedWidgets` declares its own sunset.
 * On the default server, which has no block of its own, `taggedWidgets` declares no
 * headers. Widget 1 is seeded.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ServerDefaultResponseHeadersConformance
{
    #[Test]
    public function aServersDefaultsApplyOnlyToThatServersResponses(): void
    {
        $named = $this->handle('/' . ResourcesOnServerPass::SERVER . '/plainWidgets/1');
        self::assertSame(200, $named->getStatusCode(), (string) $named->getContent());
        self::assertSame(ServerHeaderDefaults::SUNSET, $named->headers->get('Sunset'));
        self::assertContains(\sprintf('<%s>; rel="sunset"', ServerHeaderDefaults::SUNSET_LINK), $named->headers->all('link'));

        $default = $this->handle('/plainWidgets/1');
        self::assertSame(200, $default->getStatusCode(), (string) $default->getContent());
        self::assertFalse($default->headers->has('Sunset'));
        self::assertSame([], $default->headers->all('link'));
    }

    #[Test]
    public function aResourcesOwnValueOverridesItsServersDefaults(): void
    {
        $deprecated = $this->handle('/' . ResourcesOnServerPass::SERVER . '/deprecatedWidgets/1');
        self::assertSame(200, $deprecated->getStatusCode(), (string) $deprecated->getContent());
        self::assertSame('Sat, 31 Dec 2050 23:59:59 GMT', $deprecated->headers->get('Sunset'));
        self::assertSame(['<https://example.test/deprecations/widgets>; rel="sunset"'], $deprecated->headers->all('link'));

        $cached = $this->handle('/' . ResourcesOnServerPass::SERVER . '/cachedWidgets/1');
        self::assertSame(200, $cached->getStatusCode(), (string) $cached->getContent());
        self::assertSame(60, $cached->getMaxAge());
    }

    #[Test]
    public function aServersDefaultsMergeWithTheGlobalOnesFieldByField(): void
    {
        $named = $this->handle('/' . ResourcesOnServerPass::SERVER . '/plainWidgets/1');

        // The server sets no deprecation, so the global one still applies beside its sunset.
        self::assertSame('true', $named->headers->get('Deprecation'));
        self::assertSame(ServerHeaderDefaults::SUNSET, $named->headers->get('Sunset'));
    }

    #[Test]
    public function aServerWithNoDefaultsOfItsOwnUsesTheGlobalOnes(): void
    {
        $default = $this->handle('/taggedWidgets/1');
        self::assertSame(200, $default->getStatusCode(), (string) $default->getContent());
        self::assertSame(120, $default->getMaxAge());
        self::assertTrue($default->headers->hasCacheControlDirective('public'));
        self::assertSame('true', $default->headers->get('Deprecation'));
        self::assertFalse($default->headers->has('Sunset'));
        self::assertNotContains('Accept-Language', $default->getVary());
    }

    #[Test]
    public function cacheHeadersLayerResourceOverServerOverGlobal(): void
    {
        $plain = $this->handle('/' . ResourcesOnServerPass::SERVER . '/plainWidgets/1');
        self::assertSame(30, $plain->getMaxAge());
        // public comes from the global defaults, which the server block leaves alone.
        self::assertTrue($plain->headers->hasCacheControlDirective('public'));
        self::assertContains('Accept-Language', $plain->getVary());

        // The resource's max_age wins and the server's Vary is added to its own.
        $cached = $this->handle('/' . ResourcesOnServerPass::SERVER . '/cachedWidgets/1');
        self::assertSame(60, $cached->getMaxAge());
        self::assertContains('Accept-Language', $cached->getVary());
    }

    #[Test]
    public function theOpenApiDocumentsDescribeNoneOfTheseHeaders(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        foreach ([null, ResourcesOnServerPass::SERVER] as $server) {
            $json = \json_encode($factory->forServer($server)->toArray(), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('"deprecated":true', $json);
            self::assertStringNotContainsString('"Sunset"', $json);
            self::assertStringNotContainsString('"Deprecation"', $json);
        }
    }
}
