<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\ResponseHeadersTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The declarative response-header suite over a kernel with **no** global defaults
 * (bundle ADR 0054): a resource's own `cacheHeaders` reach a successful `GET` (and
 * never a write or an error), the per-operation override layers onto the collection
 * read, `Deprecation`/`Sunset` reach every method, and a resource
 * declaring nothing gets no headers at all (unchanged behaviour).
 */
final class ResponseHeadersTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ResponseHeadersTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HeaderWidgetFactory::reset();
    }

    #[Test]
    #[Group('spec:fetching')]
    public function cacheHeadersReachASingleGetRead(): void
    {
        $response = $this->handle('/cachedWidgets/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(60, $response->getMaxAge());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('Accept', $response->headers->get('Vary'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function thePerOperationOverrideLayersOntoTheCollectionRead(): void
    {
        // The resource-level max_age is 60; the `collection` override shortens it to 30.
        $response = $this->handle('/cachedWidgets');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(30, $response->getMaxAge());
        // The directives the override does not touch still come from the resource level.
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('Accept', $response->headers->get('Vary'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function cacheHeadersAreNotEmittedOnAWrite(): void
    {
        $response = $this->handle('/cachedWidgets', 'POST', [
            'data' => ['type' => 'cachedWidgets', 'attributes' => ['name' => 'fresh']],
        ]);

        self::assertSame(201, $response->getStatusCode());
        // A write is never cached: no real max-age directive (only the conservative
        // no-cache, private a bare Response computes).
        self::assertNull($response->getMaxAge());
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function cacheHeadersAreNotEmittedOnAnErrorDocument(): void
    {
        // A missing id is a 404 error document — never cached, even on a GET.
        $response = $this->handle('/cachedWidgets/999');

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($response->getMaxAge());
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function deprecationAndSunsetReachAGetRead(): void
    {
        $response = $this->handle('/deprecatedWidgets/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertSame('Sat, 31 Dec 2050 23:59:59 GMT', $response->headers->get('Sunset'));
        self::assertSame('<https://example.test/deprecations/widgets>; rel="sunset"', $response->headers->get('Link'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function deprecationAndSunsetReachAWriteToo(): void
    {
        // A deprecated endpoint is deprecated regardless of method, so the headers
        // ride a successful POST as well.
        $response = $this->handle('/deprecatedWidgets', 'POST', [
            'data' => ['type' => 'deprecatedWidgets', 'attributes' => ['name' => 'fresh']],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertSame('Sat, 31 Dec 2050 23:59:59 GMT', $response->headers->get('Sunset'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aResourceDeclaringNothingGetsNoHeaders(): void
    {
        $response = $this->handle('/plainWidgets/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->getMaxAge());
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->has('Deprecation'));
        self::assertFalse($response->headers->has('Sunset'));
    }
}
