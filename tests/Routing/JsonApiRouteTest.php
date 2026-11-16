<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Routing;

use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(JsonApiRoute::class)]
final class JsonApiRouteTest extends TestCase
{
    #[Test]
    public function aRequestCarryingTheMarkerMatches(): void
    {
        $request = Request::create('/articles');
        $request->attributes->set(JsonApiRoute::MARKER, true);

        self::assertTrue(JsonApiRoute::matches($request));
    }

    #[Test]
    public function aRequestWithoutTheMarkerDoesNot(): void
    {
        self::assertFalse(JsonApiRoute::matches(Request::create('/articles')));
    }

    #[Test]
    public function onlyATrueMarkerCounts(): void
    {
        $request = Request::create('/articles');
        $request->attributes->set(JsonApiRoute::MARKER, '1');

        self::assertFalse(JsonApiRoute::matches($request));
    }

    #[Test]
    public function theDeprecatedExceptionListenerConstantIsTheSameMarker(): void
    {
        self::assertSame(JsonApiRoute::MARKER, ExceptionListener::ROUTE_MARKER);
    }

    #[Test]
    public function aSingletonRouteReadsAsASingletonTarget(): void
    {
        $request = Request::create('/inbox');
        $request->attributes->add([
            JsonApiRoute::MARKER => true,
            '_jsonapi_server' => 'default',
            '_jsonapi_type' => 'inboxes',
            '_jsonapi_singleton' => true,
        ]);

        $route = JsonApiRoute::of($request);

        self::assertNotNull($route);
        self::assertTrue($route->singleton);
        self::assertNull($route->id);
        $target = $route->target();
        self::assertNotNull($target);
        self::assertTrue($target->singleton);
        self::assertTrue($target->addressesResource());
    }
}
