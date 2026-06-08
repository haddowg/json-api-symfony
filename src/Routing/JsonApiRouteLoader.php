<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Routing;

use haddowg\JsonApiBundle\Controller\JsonApiController;
use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A Symfony route loader for the custom `type: jsonapi` resource. For every
 * registered resource type it auto-registers the standard resource endpoint set:
 * `GET /{type}` (collection) and `GET`/`PATCH`/`DELETE` `/{type}/{id}` (single),
 * plus `POST /{type}` (create). Relationship endpoints arrive in a later phase.
 *
 * Concrete per-type paths are emitted (one literal path per type) rather than a
 * single parametric `/{type}` catch-all, so the router natively `404`s unknown
 * types — matching the bundle's "router-native, no catch-all path parsing"
 * stance. Each route carries:
 *  - `_jsonapi_type` — the resource type the {@see TargetResolver} reads;
 *  - `_jsonapi_server` — the server name (`default` in Phase 0);
 *  - the {@see ExceptionListener::ROUTE_MARKER} default that scopes the
 *    JSON:API exception listener to these routes only.
 *
 * Types are read statically from each registered Resource class-string's
 * `static $type` (no instantiation), via the {@see ResourceLocator}.
 */
final class JsonApiRouteLoader extends Loader
{
    public const string ROUTE_TYPE = 'jsonapi';

    public function __construct(private readonly ResourceLocator $resources)
    {
        parent::__construct();
    }

    /**
     * @param mixed       $resource the routing resource (unused; types come from the registry)
     * @param string|null $type     the loader type selector
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        foreach ($this->resources->classes() as $resourceClass) {
            $resourceType = $resourceClass::$type;
            if ($resourceType === '') {
                continue;
            }

            $defaults = [
                '_controller' => JsonApiController::class,
                TargetResolver::TYPE_ATTRIBUTE => $resourceType,
                '_jsonapi_server' => ServerProvider::DEFAULT_SERVER,
                ExceptionListener::ROUTE_MARKER => true,
            ];

            $collectionPath = '/' . $resourceType;
            $resourcePath = $collectionPath . '/{id}';

            $routes->add(
                \sprintf('jsonapi.%s.index', $resourceType),
                new Route($collectionPath, $defaults, methods: ['GET']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.create', $resourceType),
                new Route($collectionPath, $defaults, methods: ['POST']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.show', $resourceType),
                new Route($resourcePath, $defaults, methods: ['GET']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.update', $resourceType),
                new Route($resourcePath, $defaults, methods: ['PATCH']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.delete', $resourceType),
                new Route($resourcePath, $defaults, methods: ['DELETE']),
            );
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::ROUTE_TYPE;
    }
}
