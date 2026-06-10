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
 * `POST /{type}` (create), plus the relationship endpoints
 * `GET /{type}/{id}/{relationship}` (related resources) and
 * `GET`/`PATCH`/`POST`/`DELETE` `/{type}/{id}/relationships/{relationship}`
 * (relationship linkage read + replace/add/remove mutations).
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
 * The relationship routes additionally carry `_jsonapi_relationship_endpoint`
 * (`true` for the `/relationships/{relationship}` linkage path, `false` for the
 * `/{relationship}` related path) which the {@see TargetResolver} reads to build
 * the relationship-aware {@see \haddowg\JsonApi\Operation\Target}. The four-segment
 * linkage path and the three-segment related path differ in segment count, so they
 * never shadow one another (nor the two-segment `/{type}/{id}` resource route); the
 * linkage route is emitted first so the literal `relationships` segment is never
 * captured as a `{relationship}` name.
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
            $relationshipPath = $resourcePath . '/relationships/{relationship}';
            $relatedPath = $resourcePath . '/{relationship}';

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

            // The four-segment linkage route is listed before the three-segment
            // related route so the literal `relationships` segment is never
            // captured as a `{relationship}` name. GET reads linkage; PATCH/POST/
            // DELETE mutate it (replace / add to / remove from the relationship).
            $relationshipDefaults = [...$defaults, TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE => true];

            $routes->add(
                \sprintf('jsonapi.%s.relationship.show', $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['GET']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.relationship.update', $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['PATCH']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.relationship.add', $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['POST']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.relationship.remove', $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['DELETE']),
            );

            $routes->add(
                \sprintf('jsonapi.%s.related.show', $resourceType),
                new Route(
                    $relatedPath,
                    [...$defaults, TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE => false],
                    methods: ['GET'],
                ),
            );
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::ROUTE_TYPE;
    }
}
