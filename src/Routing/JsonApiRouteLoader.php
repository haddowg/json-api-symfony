<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Routing;

use haddowg\JsonApiBundle\Controller\JsonApiController;
use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A Symfony route loader for the custom `type: jsonapi` resource. Emission is
 * **operation-gated**: for every registered type it emits exactly one route per
 * operation that type declares (bundle ADR 0025), drawn from the standard set:
 * `GET /{type}` (collection / `FetchCollection`), `POST /{type}` (`Create`),
 * `GET`/`PATCH`/`DELETE` `/{type}/{id}` (`FetchOne` / `Update` / `Delete`). A
 * resource defaults to all five operations; a standalone serializer to none —
 * so read-only, create-only and serialize-only types all fall out of the same
 * mechanism. For full resources it additionally emits the relationship endpoints
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
 * Types, their URI path segments, and their exposed operations arrive as plain
 * scalar route descriptors built by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
 * (objects are not container-dumpable as a compiled argument). The path uses the
 * segment; route names and the `_jsonapi_type` default keep the JSON:API type.
 */
final class JsonApiRouteLoader extends Loader
{
    public const string ROUTE_TYPE = 'jsonapi';

    /**
     * @param array<string, array{uriType: string, isResource: bool, hasHydrator: bool, operations: list<string>}> $routeDescriptors
     */
    public function __construct(private readonly array $routeDescriptors = [])
    {
        parent::__construct();
    }

    /**
     * @param mixed       $resource the routing resource (unused; types come from the descriptors)
     * @param string|null $type     the loader type selector
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        foreach ($this->routeDescriptors as $resourceType => $descriptor) {
            if ($resourceType === '') {
                continue;
            }

            // The path segment may differ from the JSON:API type (e.g. a plural):
            // the descriptor carries it. Route names and the _jsonapi_type default
            // stay the JSON:API type, so Target/serializer resolution and the
            // rendered `type` member are unaffected (ADR 0022).
            $uriType = $descriptor['uriType'];
            $operations = $descriptor['operations'];

            $defaults = [
                '_controller' => JsonApiController::class,
                TargetResolver::TYPE_ATTRIBUTE => $resourceType,
                '_jsonapi_server' => ServerProvider::DEFAULT_SERVER,
                ExceptionListener::ROUTE_MARKER => true,
            ];

            $collectionPath = '/' . $uriType;
            $resourcePath = $collectionPath . '/{id}';
            $relationshipPath = $resourcePath . '/relationships/{relationship}';
            $relatedPath = $resourcePath . '/{relationship}';

            // One route per declared operation: a type that omits an operation
            // never gets its route, so unexposed verbs are unrouted (the router
            // 404s/405s natively) rather than reaching a handler that refuses.
            if (\in_array(Operation::FetchCollection->value, $operations, true)) {
                $routes->add(
                    \sprintf('jsonapi.%s.index', $resourceType),
                    new Route($collectionPath, $defaults, methods: ['GET']),
                );
            }

            if (\in_array(Operation::Create->value, $operations, true)) {
                $routes->add(
                    \sprintf('jsonapi.%s.create', $resourceType),
                    new Route($collectionPath, $defaults, methods: ['POST']),
                );
            }

            if (\in_array(Operation::FetchOne->value, $operations, true)) {
                $routes->add(
                    \sprintf('jsonapi.%s.show', $resourceType),
                    new Route($resourcePath, $defaults, methods: ['GET']),
                );
            }

            if (\in_array(Operation::Update->value, $operations, true)) {
                $routes->add(
                    \sprintf('jsonapi.%s.update', $resourceType),
                    new Route($resourcePath, $defaults, methods: ['PATCH']),
                );
            }

            if (\in_array(Operation::Delete->value, $operations, true)) {
                $routes->add(
                    \sprintf('jsonapi.%s.delete', $resourceType),
                    new Route($resourcePath, $defaults, methods: ['DELETE']),
                );
            }

            // Relationship routes only for a full resource (a standalone
            // serializer has no relations of its own to mutate or read). Gating
            // these per-relation is a later slice.
            if (!$descriptor['isResource']) {
                continue;
            }

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
