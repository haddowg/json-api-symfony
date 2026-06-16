<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Http;

use haddowg\JsonApiBundle\Http\CacheHeaders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Characterizes the {@see CacheHeaders} value object (bundle ADR 0054): the scalar
 * `fromArray()` round-trip, the directive-wise `mergeOver()` precedence, and the
 * `Cache-Control` + `Vary` it writes onto a {@see Response}.
 */
final class CacheHeadersTest extends TestCase
{
    #[Test]
    public function fromArrayReadsTheScalarDirectives(): void
    {
        $cache = CacheHeaders::fromArray([
            'max_age' => 60,
            's_maxage' => 600,
            'public' => true,
            'no_cache' => true,
            'must_revalidate' => true,
            'vary' => ['Accept', 'Authorization'],
        ]);

        self::assertSame(60, $cache->maxAge);
        self::assertSame(600, $cache->sharedMaxAge);
        self::assertTrue($cache->public);
        self::assertTrue($cache->noCache);
        self::assertTrue($cache->mustRevalidate);
        self::assertSame(['Accept', 'Authorization'], $cache->vary);
    }

    #[Test]
    public function privateWinsOverPublic(): void
    {
        $cache = CacheHeaders::fromArray(['public' => true, 'private' => true]);

        self::assertFalse($cache->public);
    }

    #[Test]
    public function anEmptyMapIsEmpty(): void
    {
        self::assertTrue((new CacheHeaders())->isEmpty());
        self::assertFalse((new CacheHeaders(maxAge: 1))->isEmpty());
    }

    #[Test]
    public function mergeOverInheritsUnsetDirectivesFromTheDefault(): void
    {
        $default = new CacheHeaders(maxAge: 120, sharedMaxAge: 600, public: true, vary: ['Accept']);
        $resource = new CacheHeaders(maxAge: 60, vary: ['Authorization']);

        $merged = $resource->mergeOver($default);

        // Own max_age wins; the rest is inherited; Vary is the union.
        self::assertSame(60, $merged->maxAge);
        self::assertSame(600, $merged->sharedMaxAge);
        self::assertTrue($merged->public);
        self::assertSame(['Accept', 'Authorization'], $merged->vary);
    }

    #[Test]
    public function applyToWritesCacheControlAndVary(): void
    {
        $response = new Response();
        (new CacheHeaders(maxAge: 60, sharedMaxAge: 600, public: true, mustRevalidate: true, vary: ['Accept']))
            ->applyTo($response);

        // getMaxAge() prefers s-maxage when both are set, so assert each directive.
        self::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('600', $response->headers->getCacheControlDirective('s-maxage'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        self::assertSame('Accept', $response->headers->get('Vary'));
    }

    #[Test]
    public function applyToEmitsPrivateForPublicFalse(): void
    {
        $response = new Response();
        (new CacheHeaders(maxAge: 30, public: false))->applyTo($response);

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }
}
