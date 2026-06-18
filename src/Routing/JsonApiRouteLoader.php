<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Routing;

use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Controller\JsonApiController;
use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
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
 * stance. When a type's resource Id field declares a route pattern (via
 * {@see \haddowg\JsonApi\Resource\Field\Id::matchAs()} or the
 * `uuid()`/`ulid()`/`numeric()`/`pattern()` shortcuts, ADR 0038), every route that
 * carries `{id}` constrains that segment with it — resolved through the injected
 * {@see IdEncoderResolver} — so a malformed id `404`s at routing before any handler
 * runs. No pattern means the `{id}` stays unconstrained (today's behaviour). Each
 * route carries:
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
     * The route default carrying a custom action's name (the `{action}` segment),
     * read by the {@see \haddowg\JsonApiBundle\EventListener\RequestListener} to
     * branch into the action dispatch path (bundle ADR 0076).
     */
    public const string ACTION_ATTRIBUTE = '_jsonapi_action';

    /**
     * The route default carrying a custom action's scope (the {@see ActionScope}
     * case name), so the request listener and registry agree on the resource /
     * collection scope without re-deriving it from the presence of `{id}`.
     */
    public const string ACTION_SCOPE_ATTRIBUTE = '_jsonapi_action_scope';

    /**
     * The reserved path segment custom actions hang off (design §1/§7). It is a
     * **literal** in every action path and must never be captured as a resource
     * `{id}`: a collection-scope action `/{uriType}/-actions/{name}` is three
     * segments — structurally identical to the generic related route
     * `GET /{uriType}/{id}/{relationship}` — so the action route (declared methods
     * only) shields just its own verbs, and any other method would otherwise fall
     * through to the related route with `{id}` = the literal `-actions`. Excluding
     * it from every `{id}` requirement (see {@see idRequirement()}) makes §7's
     * "the literal `-actions` is never captured as an `{id}`" guarantee hold for
     * *all* methods, not only the ordering between same-method routes.
     */
    private const string RESERVED_ACTIONS_SEGMENT = '-actions';

    /**
     * @param array<string, array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>}>> $routeDescriptorsByServer       keyed by server name, then by JSON:API type
     * @param array<string, list<array{uriType: string, type: string, path: string, methods: list<string>, scope: string, name: string}>>            $actionRouteDescriptorsByServer keyed by server name; the custom-action routes to emit before the generic routes (bundle ADR 0076)
     * @param ?IdEncoderResolver                                                                                                                          $idEncoders                     resolves each type's route `{id}` pattern (null in a bare scalar-only test wiring)
     */
    public function __construct(
        private readonly array $routeDescriptorsByServer = [],
        private readonly array $actionRouteDescriptorsByServer = [],
        private readonly ?IdEncoderResolver $idEncoders = null,
    ) {
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

        // Custom action routes (bundle ADR 0076) are emitted FIRST so the literal
        // `-actions` segment is never captured as an `{id}` (a collection-scope
        // action's `/{uriType}/-actions/{action}` would otherwise be shadowed by the
        // related route `/{uriType}/{id}/{relationship}`) or a `{relationship}` name.
        $this->addActionRoutes($routes, $server);

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

            // A resource whose Id field declares a route pattern (matchAs / the
            // uuid()/ulid()/numeric()/pattern() shortcuts, ADR 0038) constrains the
            // {id} segment on every route that carries it, so a malformed id 404s at
            // routing before any handler runs. The requirement always excludes the
            // reserved `-actions` segment (see {@see idRequirement()}) so an action
            // path is never shadowed by a generic {id} route on a non-action method.
            $idRequirements = ['id' => $this->idRequirement($resourceType)];

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
                    new Route($resourcePath, $defaults, $idRequirements, methods: ['GET']),
                );
            }

            if (\in_array(Operation::Update->value, $operations, true)) {
                $routes->add(
                    \sprintf('%s%s.update', $namePrefix, $resourceType),
                    new Route($resourcePath, $defaults, $idRequirements, methods: ['PATCH']),
                );
            }

            if (\in_array(Operation::Delete->value, $operations, true)) {
                $routes->add(
                    \sprintf('%s%s.delete', $namePrefix, $resourceType),
                    new Route($resourcePath, $defaults, $idRequirements, methods: ['DELETE']),
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
                new Route($relationshipPath, $relationshipDefaults, $idRequirements, methods: ['GET']),
            );

            $routes->add(
                \sprintf('%s%s.relationship.update', $namePrefix, $resourceType),
                new Route($relationshipPath, $relationshipDefaults, $idRequirements, methods: ['PATCH']),
            );

            $routes->add(
                \sprintf('%s%s.relationship.add', $namePrefix, $resourceType),
                new Route($relationshipPath, $relationshipDefaults, $idRequirements, methods: ['POST']),
            );

            $routes->add(
                \sprintf('%s%s.relationship.remove', $namePrefix, $resourceType),
                new Route($relationshipPath, $relationshipDefaults, $idRequirements, methods: ['DELETE']),
            );

            $routes->add(
                \sprintf('%s%s.related.show', $namePrefix, $resourceType),
                new Route(
                    $relatedPath,
                    [...$defaults, TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE => false],
                    $idRequirements,
                    methods: ['GET'],
                ),
            );
        }

        return $routes;
    }

    /**
     * Emits the custom-action routes for `$server` (bundle ADR 0076, design §7),
     * each under the reserved `-actions` segment with the action's author-declared
     * HTTP methods. The single `{action}` segment is emitted as a **literal** (the
     * action's own name), not a parametric `{action}`, so two actions of the same
     * (type, scope) but different methods/inputs never collapse onto one parametric
     * path — each is its own concrete route the router dispatches by method:
     *  - resource scope: `/{uriType}/{id}/-actions/{name}` (4 segments) — the `{id}`
     *    is resolved to an entity before the handler runs; it carries the type's
     *    route `{id}` pattern when one is declared (ADR 0038);
     *  - collection scope: `/{uriType}/-actions/{name}` (3 segments) — no id.
     *
     * Each route carries the standard JSON:API defaults plus `_jsonapi_action` (the
     * action name) and `_jsonapi_action_scope` (the scope case name) the request
     * listener branches on. The route name is stable —
     * `jsonapi[.{server}].{type}.action.{scope}.{name}` — honouring an optional
     * `name` override.
     */
    private function addActionRoutes(RouteCollection $routes, string $server): void
    {
        $namePrefix = $server === ServerProvider::DEFAULT_SERVER
            ? 'jsonapi.'
            : \sprintf('jsonapi.%s.', $server);

        foreach ($this->actionRouteDescriptorsByServer[$server] ?? [] as $action) {
            $type = $action['type'];
            $uriType = $action['uriType'];
            $path = $action['path'];
            $scopeName = $action['scope'];
            $methods = $action['methods'];

            $resourceScope = $scopeName === ActionScope::Resource->name;

            $defaults = [
                '_controller' => JsonApiController::class,
                TargetResolver::TYPE_ATTRIBUTE => $type,
                '_jsonapi_server' => $server,
                ExceptionListener::ROUTE_MARKER => true,
                self::ACTION_ATTRIBUTE => $path,
                self::ACTION_SCOPE_ATTRIBUTE => $scopeName,
            ];

            $requirements = [];

            if ($resourceScope) {
                $actionPath = \sprintf('/%s/{id}/-actions/%s', $uriType, $path);

                // Constrain {id} with the type's declared id pattern (ADR 0038) so a
                // malformed id 404s at routing, exactly as the CRUD resource routes;
                // the requirement also excludes the reserved `-actions` segment.
                $requirements['id'] = $this->idRequirement($type);
            } else {
                $actionPath = \sprintf('/%s/-actions/%s', $uriType, $path);
            }

            $routeName = $action['name'] !== ''
                ? $namePrefix . $action['name']
                : \sprintf('%s%s.action.%s.%s', $namePrefix, $type, \strtolower($scopeName), $path);

            $routes->add($routeName, new Route($actionPath, $defaults, $requirements, methods: $methods));
        }
    }

    /**
     * The `{id}` route requirement for `$type` — the inner regex (no anchors;
     * Symfony anchors requirements itself) every route carrying an `{id}` uses.
     *
     * It always excludes the reserved {@see RESERVED_ACTIONS_SEGMENT} via a leading
     * negative lookahead, so the literal `-actions` can never be captured as a
     * resource id (design §7) — closing the collection-scope action shadow where a
     * non-action method (e.g. `GET /{uriType}/-actions/{name}`) would otherwise fall
     * through to the generic related route `GET /{uriType}/{id}/{relationship}` with
     * `{id}` = `-actions`, bypassing the action's authz/dispatch entirely.
     *
     * When the type's Id field declares a route pattern (matchAs / the
     * uuid()/ulid()/numeric()/pattern() shortcuts, ADR 0038), the author pattern is
     * preserved and the lookahead is composed in front of it (so a malformed id
     * still 404s at routing); with no declared pattern the requirement is the
     * single-segment default `[^/]+` (Symfony's implicit placeholder regex), now
     * minus `-actions`.
     */
    private function idRequirement(string $type): string
    {
        $authorPattern = $this->idEncoders?->routePatternFor($type);

        // With no author pattern, keep Symfony's implicit single-segment placeholder
        // regex (`[^/]+`) so the {id} still cannot span a `/` boundary; the negative
        // lookahead only carves out the reserved `-actions` literal.
        $body = $authorPattern !== null ? '(?:' . $authorPattern . ')' : '[^/]+';

        // The lookahead is anchored to the *segment* boundary — `-actions` followed
        // by the next path separator or the end of the path — NOT `$` (end of the
        // whole string). Symfony embeds the requirement inside a larger compiled
        // matcher regex, so a bare `(?!-actions$)` would only fire when `-actions`
        // is the final segment; a collection-scope action `/{uriType}/-actions/{name}`
        // has `/{name}` after it, so `$` never matches there and the exclusion would
        // silently fail. `(?:/|$)` covers both the followed-by-`/` and trailing cases.
        return \sprintf('(?!%s(?:/|$))%s', \preg_quote(self::RESERVED_ACTIONS_SEGMENT, '#'), $body);
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
