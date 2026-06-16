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
 * mechanism. For any type that declares relations — a full resource, or a
 * resource-less type that declared standalone relations (ADR 0026) — it
 * additionally emits the relationship endpoints
 * `GET /{type}/{id}/{relationship}` (related resources) and
 * `GET`/`PATCH`/`POST`/`DELETE` `/{type}/{id}/relationships/{relationship}`
 * (relationship linkage read + replace/add/remove mutations).
 *
 * Concrete per-type paths are emitted (one literal path per type) rather than a
 * single parametric `/{type}` catch-all, so the router natively `404`s unknown
 * types — matching the bundle's "router-native, no catch-all path parsing"
 * stance. Each route carries:
 *  - `_jsonapi_type` — the resource type the {@see TargetResolver} reads;
 *  - `_jsonapi_server` — the server name (`default` for the unnamed import);
 *  - the {@see ExceptionListener::ROUTE_MARKER} default that scopes the
 *    JSON:API exception listener to these routes only.
 *
 * Multi-server (bundle ADR 0034): each routing import names a server through its
 * resource string (`$routes->import('admin', 'jsonapi')`; the bare `.` / empty
 * import is the `default` server). The loader emits only that server's descriptors,
 * stamps each route's `_jsonapi_server` with the resolved name, and namespaces the
 * route names — the `default` server keeps the existing unprefixed
 * `jsonapi.{type}.{action}` names, a named server uses `jsonapi.{server}.{type}.{action}`
 * — so a type exposed on two servers never collides. Prefix/host/condition stay in
 * the application's routing config (Symfony applies the import's `prefix()` to the
 * emitted paths). An unknown or empty server emits nothing.
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
 * (objects are not container-dumpable as a compiled argument), keyed per server.
 * The path uses the segment; route names and the `_jsonapi_type` default keep the
 * JSON:API type.
 */
final class JsonApiRouteLoader extends Loader
{
    public const string ROUTE_TYPE = 'jsonapi';

    /**
     * @param array<string, array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>}>> $routeDescriptorsByServer keyed by server name, then by JSON:API type
     */
    public function __construct(private readonly array $routeDescriptorsByServer = [])
    {
        parent::__construct();
    }

    /**
     * @param mixed       $resource the routing resource: a non-empty, non-`.` string names the server; otherwise `default`
     * @param string|null $type     the loader type selector
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        $server = $this->serverName($resource);
        $descriptors = $this->routeDescriptorsByServer[$server] ?? [];

        foreach ($descriptors as $resourceType => $descriptor) {
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
                '_jsonapi_server' => $server,
                ExceptionListener::ROUTE_MARKER => true,
            ];

            // The default server keeps the existing unprefixed route names; a named
            // server namespaces them so a type exposed on two servers never collides.
            $namePrefix = $server === ServerProvider::DEFAULT_SERVER
                ? 'jsonapi.'
                : \sprintf('jsonapi.%s.', $server);

            $collectionPath = '/' . $uriType;
            $resourcePath = $collectionPath . '/{id}';
            $relationshipPath = $resourcePath . '/relationships/{relationship}';
            $relatedPath = $resourcePath . '/{relationship}';

            // One route per declared operation: a type that omits an operation
            // never gets its route, so unexposed verbs are unrouted (the router
            // 404s/405s natively) rather than reaching a handler that refuses.
            if (\in_array(Operation::FetchCollection->value, $operations, true)) {
                $routes->add(
                    \sprintf('%s%s.index', $namePrefix, $resourceType),
                    new Route($collectionPath, $defaults, methods: ['GET']),
                );
            }

            if (\in_array(Operation::Create->value, $operations, true)) {
                $routes->add(
                    \sprintf('%s%s.create', $namePrefix, $resourceType),
                    new Route($collectionPath, $defaults, methods: ['POST']),
                );
            }

            if (\in_array(Operation::FetchOne->value, $operations, true)) {
                $routes->add(
                    \sprintf('%s%s.show', $namePrefix, $resourceType),
                    new Route($resourcePath, $defaults, methods: ['GET']),
                );
            }

            if (\in_array(Operation::Update->value, $operations, true)) {
                $routes->add(
                    \sprintf('%s%s.update', $namePrefix, $resourceType),
                    new Route($resourcePath, $defaults, methods: ['PATCH']),
                );
            }

            if (\in_array(Operation::Delete->value, $operations, true)) {
                $routes->add(
                    \sprintf('%s%s.delete', $namePrefix, $resourceType),
                    new Route($resourcePath, $defaults, methods: ['DELETE']),
                );
            }

            // Relationship routes for any type that declares relations — a full
            // resource (which always bundles relations) or a resource-less type that
            // declared standalone relations via #[AsJsonApiRelations] (ADR 0026).
            // Gating these per-relation is a later slice.
            if (!$descriptor['hasRelations']) {
                continue;
            }

            // The four-segment linkage route is listed before the three-segment
            // related route so the literal `relationships` segment is never
            // captured as a `{relationship}` name. GET reads linkage; PATCH/POST/
            // DELETE mutate it (replace / add to / remove from the relationship).
            $relationshipDefaults = [...$defaults, TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE => true];

            $routes->add(
                \sprintf('%s%s.relationship.show', $namePrefix, $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['GET']),
            );

            $routes->add(
                \sprintf('%s%s.relationship.update', $namePrefix, $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['PATCH']),
            );

            $routes->add(
                \sprintf('%s%s.relationship.add', $namePrefix, $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['POST']),
            );

            $routes->add(
                \sprintf('%s%s.relationship.remove', $namePrefix, $resourceType),
                new Route($relationshipPath, $relationshipDefaults, methods: ['DELETE']),
            );

            $routes->add(
                \sprintf('%s%s.related.show', $namePrefix, $resourceType),
                new Route(
                    $relatedPath,
                    [...$defaults, TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE => false],
                    methods: ['GET'],
                ),
            );
        }

        return $routes;
    }

    /**
     * The server name a routing import targets: a non-empty string that is not the
     * bare `.` import marker is the server name; otherwise the implicit `default`.
     */
    private function serverName(mixed $resource): string
    {
        if (\is_string($resource) && $resource !== '' && $resource !== '.') {
            return $resource;
        }

        return ServerProvider::DEFAULT_SERVER;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::ROUTE_TYPE;
    }
}
