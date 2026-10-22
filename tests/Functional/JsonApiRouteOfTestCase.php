<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContextAwareInterface;

/**
 * `JsonApiRoute::of()` against the attributes the kernel's real router gives a request.
 */
abstract class JsonApiRouteOfTestCase extends JsonApiFunctionalTestCase
{
    protected function route(string $method, string $path): JsonApiRoute
    {
        $route = JsonApiRoute::of($this->matched($method, $path));
        self::assertNotNull($route);

        return $route;
    }

    protected function matched(string $method, string $path): Request
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RequestMatcherInterface::class, $router);
        self::assertInstanceOf(RequestContextAwareInterface::class, $router);

        // As the RouterListener does: older routers match against the context's method.
        $request = Request::create($path, $method);
        $router->getContext()->fromRequest($request);
        $request->attributes->add($router->matchRequest($request));

        return $request;
    }
}
