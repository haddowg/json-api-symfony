<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiMultiServerTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * `JsonApiRoute::of()` on collection and resource routes of two servers, and on routes
 * the bundle didn't emit.
 */
final class JsonApiRouteOfResourceTest extends JsonApiRouteOfTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiMultiServerTestKernel::class;
    }

    #[Test]
    public function aCollectionRoute(): void
    {
        $route = $this->route('GET', '/public-items');

        self::assertEquals(new JsonApiRoute('default', 'public-items'), $route);
        self::assertEquals(new Target('public-items'), $route->target());
    }

    #[Test]
    public function aResourceRoute(): void
    {
        $route = $this->route('PATCH', '/public-items/7');

        self::assertEquals(new JsonApiRoute('default', 'public-items', '7'), $route);
        self::assertEquals(new Target('public-items', '7'), $route->target());
    }

    #[Test]
    public function aNamedServersRoute(): void
    {
        $route = $this->route('GET', '/admin/admin-items/3');

        self::assertSame('admin', $route->server);
        self::assertSame('admin-items', $route->type);
    }

    #[Test]
    public function aRouteTheBundleDidNotEmitHasNone(): void
    {
        $request = $this->matched('GET', '/docs.json');

        self::assertFalse(JsonApiRoute::matches($request));
        self::assertNull(JsonApiRoute::of($request));
    }

    #[Test]
    public function anUnroutedRequestHasNone(): void
    {
        self::assertNull(JsonApiRoute::of(Request::create('/public-items')));
    }
}
