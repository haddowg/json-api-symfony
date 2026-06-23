<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Event\ServingEvent;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipLinkage;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipPagination;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Builds the immutable core {@see Server} for one declared server (bundle ADR
 * 0034) from:
 *  - this server's Resource class-strings (the subset of the discovered resources
 *    assigned to it via `#[AsJsonApiResource(server: …)]`), registered through
 *    core's lazy container resolver — held by the shared {@see ResourceLocator},
 *    which stays the global resolver/container — so a Resource can have real
 *    constructor dependencies;
 *  - this server's base URI and JSON:API version;
 *  - the PSR-17 response / stream factories;
 *  - the server-wide default paginator, resolved in order: (1) a
 *    {@see PaginatorInterface} service registered for *this* server
 *    (`haddowg.json_api.default_paginator.<name>`); else (2) a generic one
 *    registered for all servers (`haddowg.json_api.default_paginator`); else (3)
 *    the built-in {@see PagePaginator} whose client-controlled `page[size]` is
 *    capped at `json_api.pagination.max_per_page` (default 100), so every
 *    collection is protected from a page-size DoS without per-resource
 *    configuration; else (4) `null` (cap set to 0) — no server default, so a
 *    collection that resolves to it renders unpaginated. A resource's own
 *    `pagination()` overrides whichever is resolved.
 *
 * One factory is built per declared server in
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::loadExtension()}; the implicit
 * `default` server is just the unnamed one carrying the top-level base_uri/version.
 *
 * It deliberately does **not** install core's PSR-15 `Middleware\*` chain — the
 * bundle drives the request lifecycle from kernel listeners that call
 * {@see Server::dispatch()}. It does call `withHandler()` with the bundle's own
 * read handler so `dispatch()` has a target (core's `dispatch()` throws without
 * one).
 *
 * The built Server is an immutable value, so it is memoized and shared.
 *
 * It also threads the server default `?include` depth cap
 * (`json_api.max_include_depth`, default 3) into the Server through
 * {@see Server::withMaxIncludeDepth()} — a non-positive configured value resolving
 * to `null` (unlimited), which a resource's own `maxIncludeDepth()` may still
 * override per type (bundle ADR 0037).
 *
 * It threads the strict query-parameter toggle
 * (`json_api.strict_query_parameters`, default true) into the Server through
 * {@see Server::withStrictQueryParameters()} (bundle ADR 0055, core ADR 0059): when
 * on, an unrecognized top-level query-parameter family is rejected with a `400`
 * {@see \haddowg\JsonApi\Exception\QueryParamUnrecognized}, thrown from
 * `Server::dispatch()` and rendered by the route-scoped exception listener; when
 * off, an unrecognized family is silently ignored (the old behaviour).
 *
 * When a {@see RelationshipLoadStateInterface} is wired (the reference Doctrine
 * predicate, present only on a Doctrine application), it is threaded into the
 * Server through core's
 * {@see Server::withRelationshipLoadState()} injector so a relation that opted
 * into load-aware linkage can omit `data` rather than force a lazy load; when no
 * predicate is wired the argument is null and core treats every relation as
 * loaded (today's behaviour).
 */
final class ServerFactory
{
    private ?Server $server = null;

    /**
     * @param list<class-string<\haddowg\JsonApi\Resource\AbstractResource>>                      $resourceClasses       this server's Resource class-strings (ADR 0034)
     * @param array<class-string, class-string<\haddowg\JsonApi\Serializer\SerializerInterface>> $serializersByClass    override serializer per Resource class-string (ADR 0023), this server only
     * @param array<class-string, class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>>      $hydratorsByClass      override hydrator per Resource class-string (ADR 0023), this server only
     * @param array<string, class-string<\haddowg\JsonApi\Serializer\SerializerInterface>>        $standaloneSerializers standalone serializer per type, no resource (ADR 0024), this server only
     * @param array<string, class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>>            $standaloneHydrators   standalone hydrator per type, no resource (ADR 0024), this server only
     */
    public function __construct(
        private readonly ResourceLocator $resources,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUri,
        private readonly string $version,
        private readonly OperationHandlerInterface $handler,
        private readonly int $maxPerPage = PagePaginator::DEFAULT_MAX_PER_PAGE,
        private readonly int $maxIncludeDepth = 0,
        private readonly bool $strictQueryParameters = true,
        private readonly ?PaginatorInterface $serverDefaultPaginator = null,
        private readonly ?PaginatorInterface $defaultPaginator = null,
        private readonly ?RelationshipLoadStateInterface $relationshipLoadState = null,
        private readonly ?RequestScopedRelationshipCount $relationshipCount = null,
        private readonly ?RequestScopedRelationshipPagination $relationshipPagination = null,
        private readonly ?RequestScopedRelationshipLinkage $relationshipLinkage = null,
        private readonly ?ResourceLinkContributorInterface $resourceLinkContributor = null,
        private readonly array $resourceClasses = [],
        private readonly array $serializersByClass = [],
        private readonly array $hydratorsByClass = [],
        private readonly array $standaloneSerializers = [],
        private readonly array $standaloneHydrators = [],
        private readonly string $serverName = 'default',
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {}

    /**
     * The configured, memoized Server for this server's API surface.
     */
    public function create(): Server
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $server = Server::make()
            ->withBaseUri($this->baseUri)
            ->withVersion($this->version)
            ->withPsr17($this->responseFactory, $this->streamFactory)
            ->withRelationshipLoadState($this->relationshipLoadState)
            // The per-request count seam: a stable holder threaded in once (the
            // Server is memoized), whose batched backing the handler swaps per read
            // so the render emits `meta.total` for ?withCount-named countable
            // relations (bundle ADR 0052). Null when no holder is wired — core then
            // omits every relationship-object total.
            ->withRelationshipCount($this->relationshipCount)
            // The per-request relationship-window seam (bundle ADR 0053): the same
            // stable-holder indirection as the count seam, threaded in once. The
            // handler swaps its windowed backing per profile read so core renders the
            // relationship-object pagination links for each rendered to-many; null
            // when the profile read did not negotiate, so no such links are emitted.
            ->withRelationshipPagination($this->relationshipPagination)
            // The per-request relationship-LINKAGE seam (bundle ADR 0086): the same
            // stable-holder indirection again, threaded in once. The handler swaps its
            // windowed-linkage backing per profile read so core renders a windowed
            // to-many's page-1 linkage WITHOUT the batcher writing it onto the parent
            // property — leaving any column-sharing bystander relation to render its own
            // membership. Null when the profile read did not negotiate, so core reads
            // linkage off the model as before.
            ->withRelationshipLinkage($this->relationshipLinkage)
            // The out-of-band resource-link contributor (bundle ADR 0091): merges
            // host-owned, router-generated links onto a rendered resource alongside its
            // author getLinks() (author keys win) — here, a custom action exposed via
            // #[AsJsonApiAction(asLink: true)], security-aware so the link renders only
            // when the requester may invoke the action. Per server (only this server's
            // asLink actions); null when no asLink action is declared on this server, so
            // core renders links exactly as before this seam existed.
            ->withResourceLinkContributor($this->resourceLinkContributor)
            // Recognize the Relationship Queries profile (core ADR 0058) so the
            // response advertises it (Content-Type `profile` param + `jsonapi.profile`)
            // when a client negotiates it; the opt-in relatedQuery/rQ parse is gated
            // on the Accept `profile` param, the rendering on this registration.
            ->withProfile(new RelationshipQueriesProfile())
            // Recognize the Countable profile (core ADR 0065, renamed under G21) so the
            // opt-in `?withCount` family is parsed and recognized only when a client
            // negotiates it; the response then advertises it like any applied profile.
            // The `?withCount` query-param name is unchanged — only the profile's
            // identity/URI moved from "Relationship Counts" to "Countable".
            ->withProfile(new CountableProfile())
            // The server-wide default paginator (the tail of core's `relation →
            // related resource → server default` fallback), resolved per server.
            ->withDefaultPaginator($this->resolveDefaultPaginator())
            // The server default `?include` depth cap (json_api.max_include_depth);
            // a non-positive value means unlimited (null), which a resource's own
            // maxIncludeDepth() may still override.
            ->withMaxIncludeDepth($this->maxIncludeDepth > 0 ? $this->maxIncludeDepth : null)
            // Reject an unrecognized top-level query-parameter family with a 400
            // (json_api.strict_query_parameters, default true; bundle ADR 0055, core
            // ADR 0059). The recognized set is assembled per request from the reserved
            // JSON:API families, the primary resource's declared keys, the always-on
            // `withCount`, and a negotiated profile's keywords — so the Relationship
            // Queries profile's relatedQuery/rQ family (registered via withProfile
            // above) is recognized automatically when the client negotiates it, with
            // no extra registration. False restores the old silent-ignore behaviour.
            ->withStrictQueryParameters($this->strictQueryParameters)
            ->withContainer($this->resources);

        foreach ($this->resourceClasses as $resourceClass) {
            // A resource may override its serializer/hydrator (ADR 0023); core
            // resolves the override class through the same container resolver and
            // drives the type's reads/writes through it instead of the resource.
            $server = $server->register(
                $resourceClass,
                $this->serializersByClass[$resourceClass] ?? null,
                $this->hydratorsByClass[$resourceClass] ?? null,
            );
        }

        // Standalone serializer/hydrator capabilities (ADR 0024): a type registered
        // with no resource. Core stores the pair; serializerFor()/hydratorFor()
        // resolve the services through the same locator. Serialize-only by default
        // (no routes) — a later slice's operation allow-list exposes endpoints.
        foreach ($this->standaloneTypes() as $type) {
            $server = $server->registerSerializerHydrator(
                $type,
                $this->standaloneSerializers[$type] ?? null,
                $this->standaloneHydrators[$type] ?? null,
            );
        }

        // The serving bridge (bundle ADR 0042): one core `serving` handler that
        // turns core's server-level seam (fired once per request inside
        // `Server::dispatch()`, core ADR 0050) into a bundle `ServingEvent`. A
        // ServingEvent subscriber that throws a JsonApiException aborts — the throw
        // propagates out of the closure → out of `dispatch()` → the route-scoped
        // ExceptionListener. Registered only when a dispatcher is wired (the events
        // are an opt-in seam).
        if ($this->dispatcher !== null) {
            $dispatcher = $this->dispatcher;
            $serverName = $this->serverName;
            $server = $server->withServing(
                static function (JsonApiRequestInterface $request) use ($dispatcher, $serverName): void {
                    $dispatcher->dispatch(new ServingEvent($request, $serverName));
                },
            );
        }

        return $this->server = $server->withHandler($this->handler);
    }

    /**
     * The server-wide default paginator, resolved in precedence order:
     *  1. a {@see PaginatorInterface} service registered for *this* server
     *     (`haddowg.json_api.default_paginator.<name>`) — lets an app install a
     *     cursor/offset strategy on one server;
     *  2. else a generic one registered for all servers
     *     (`haddowg.json_api.default_paginator`);
     *  3. else the built-in {@see PagePaginator} capped at the configured
     *     `json_api.pagination.max_per_page` (a page-size DoS bound, default 100);
     *  4. else `null` (cap set to 0) — no server default, so a collection
     *     resolving to it renders unpaginated.
     *
     * A custom paginator owns its own page-size ceiling, so the `max_per_page`
     * cap applies only to the built-in fallback (3).
     */
    private function resolveDefaultPaginator(): ?PaginatorInterface
    {
        return $this->serverDefaultPaginator
            ?? $this->defaultPaginator
            ?? ($this->maxPerPage > 0 ? PagePaginator::make()->withMaxPerPage($this->maxPerPage) : null);
    }

    /**
     * The distinct types declared by a standalone serializer and/or hydrator.
     *
     * @return list<string>
     */
    private function standaloneTypes(): array
    {
        return \array_values(\array_unique([
            ...\array_keys($this->standaloneSerializers),
            ...\array_keys($this->standaloneHydrators),
        ]));
    }
}
