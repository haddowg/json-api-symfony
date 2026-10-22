<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Routing;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Decorates the application's root route loader (`routing.loader`) to record where
 * each JSON:API server is mounted (bundle ADR 0121).
 *
 * {@see JsonApiRouteLoader} cannot see the `prefix` of the import that loads it,
 * because Symfony applies the prefix to the returned collection afterwards. Once the
 * whole collection is loaded, every JSON:API route carries its loader-emitted path
 * ({@see JsonApiRouteLoader::PATH_ATTRIBUTE}) next to its final, prefixed path, and
 * the difference is the mount. This loader writes it to
 * {@see JsonApiRouteLoader::MOUNT_ATTRIBUTE}, so it is compiled into the cached
 * router with the rest of the defaults and costs nothing per request.
 */
final class MountRecordingLoader implements LoaderInterface
{
    public function __construct(private readonly LoaderInterface $inner) {}

    public function load(mixed $resource, ?string $type = null): mixed
    {
        $routes = $this->inner->load($resource, $type);

        if ($routes instanceof RouteCollection) {
            self::recordMounts($routes);
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $this->inner->supports($resource, $type);
    }

    public function getResolver(): LoaderResolverInterface
    {
        return $this->inner->getResolver();
    }

    public function setResolver(LoaderResolverInterface $resolver): void
    {
        $this->inner->setResolver($resolver);
    }

    public static function recordMounts(RouteCollection $routes): void
    {
        foreach ($routes as $route) {
            $emitted = $route->getDefault(JsonApiRouteLoader::PATH_ATTRIBUTE);
            if (!\is_string($emitted) || $emitted === '') {
                continue;
            }

            $path = $route->getPath();
            if ($path === $emitted || !\str_ends_with($path, $emitted)) {
                continue;
            }

            $route->setDefault(JsonApiRouteLoader::MOUNT_ATTRIBUTE, \substr($path, 0, -\strlen($emitted)));
        }
    }
}
