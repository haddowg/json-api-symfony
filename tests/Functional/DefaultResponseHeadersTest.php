<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Http\DefaultResponseHeadersTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The global-default response-header suite (bundle ADR 0054): under a kernel that
 * declares `json_api.defaults.cache_headers` + `deprecation`, a resource declaring
 * nothing inherits the defaults, and a resource declaring its own `cacheHeaders`
 * overrides the default cache while still inheriting the default deprecation —
 * proving the resource-level value merges over the global default.
 */
final class DefaultResponseHeadersTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return DefaultResponseHeadersTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HeaderWidgetFactory::reset();
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aResourceDeclaringNothingInheritsTheGlobalDefaults(): void
    {
        $response = $this->handle('/plainWidgets/1');

        self::assertSame(200, $response->getStatusCode());
        // The default cache (max_age: 120, public).
        self::assertSame(120, $response->getMaxAge());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        // The default deprecation.
        self::assertSame('true', $response->headers->get('Deprecation'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aResourceLevelCacheOverridesTheDefault(): void
    {
        // cachedWidgets declares max_age: 60 — its own value wins over the default 120.
        $response = $this->handle('/cachedWidgets/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(60, $response->getMaxAge());
        // It declares no deprecation, so it still inherits the default Deprecation.
        self::assertSame('true', $response->headers->get('Deprecation'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theDefaultDeprecationDoesNotCacheAWrite(): void
    {
        // The default cache is not applied to a write, but the default deprecation is.
        $response = $this->handle('/plainWidgets', 'POST', [
            'data' => ['type' => 'plainWidgets', 'attributes' => ['name' => 'fresh']],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertNull($response->getMaxAge());
        self::assertSame('true', $response->headers->get('Deprecation'));
    }
}
