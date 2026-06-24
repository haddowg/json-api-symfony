<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Action\ActionDescriptor;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Security\ResourceSecurity;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * The bundle's implementation of core's OpenAPI metadata contract (design §3, §4.6,
 * §4.7, D8/D15) — it builds, for one server, the {@see ServerMetadataInterface}
 * (with its {@see TypeMetadataInterface} family) the core
 * {@see \haddowg\JsonApi\OpenApi\OpenApiProjector} projects into an OpenAPI document.
 *
 * It reads the live, compiled registry — the per-server route descriptors
 * ({@see RouteDescriptorRegistry}, the authoritative per-server type list with each
 * type's uriType / operations / tags / resource-or-standalone shape), the
 * {@see TypeMetadataResolver} (resource + relations, tolerating a resource-less
 * type), the {@see IdEncoderResolver} (client-id policy), the
 * {@see ActionRegistry} (custom actions), and the optional
 * {@see ResourceSecurityRegistry} (per-operation security intent) — and folds in the
 * config-shaped {@see ServerDocumentConfig} (info / advertised servers / tag
 * definitions / security schemes, wired from `json_api.openapi.*` in stage B).
 *
 * **Operation enum mapping**: the bundle's {@see Operation} (route allow-list) and
 * core's {@see OperationType} share the same five case names AND backing values, so a
 * value maps directly via {@see OperationType::tryFrom()}.
 *
 * **Security** (design §4.6/D8): `securedOperations()` reports only *which*
 * operations carry a security expression (never the expression). The mapping follows
 * the runtime enforcement exactly (the {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber}):
 * `forCreate`/`forUpdate`/`forDelete` gate Create/Update/Delete, and `forRead` gates
 * **only** FetchOne (read security is enforced at the single-read hook; there is no
 * collection-read gate, so FetchCollection is never secured by this layer). The
 * security *schemes* + *default requirement* are config — carried on the source's
 * config and surfaced on the {@see ServerMetadata}.
 *
 * **Tags** (design §4.7/D15): a type's tag refs are the explicit descriptor `tags`,
 * else the humanized-type default; the document's tag definitions are the config
 * definitions, unioned with name-only synthesized {@see Tag}s for any
 * referenced-but-undefined tag (config order first, then discovery order).
 */
final class MetadataSource
{
    /**
     * @param array<string, ServerDocumentConfig> $configByServer the per-server document config (info / servers / tags / security), keyed by server name; a server with no entry uses defaults
     * @param bool                                 $atomicEnabled  whether the global Atomic Operations extension is enabled (`json_api.atomic_operations.enabled`); when true every server's document gains the atomic endpoint, mirroring the route loader
     * @param string                               $atomicPath     the path the per-server atomic endpoint is served at (`json_api.atomic_operations.path`, default `/operations`)
     */
    public function __construct(
        private readonly ServerProvider $servers,
        private readonly RouteDescriptorRegistry $descriptors,
        private readonly TypeMetadataResolver $types,
        private readonly IdEncoderResolver $idEncoders,
        private readonly ActionRegistry $actions,
        private readonly PaginatorKindResolver $paginatorKinds,
        private readonly TagNameResolver $tagNames,
        private readonly IncludePathResolver $includePaths,
        private readonly ?ResourceSecurityRegistry $security = null,
        private readonly ?ResourceDescriptionRegistry $descriptions = null,
        private readonly array $configByServer = [],
        private readonly bool $atomicEnabled = false,
        private readonly string $atomicPath = '/operations',
    ) {}

    /**
     * The complete OpenAPI metadata for `$serverName` (the implicit `default` when
     * null), ready for the core projector.
     */
    public function forServer(?string $serverName = null): ServerMetadataInterface
    {
        $serverName ??= ServerProvider::DEFAULT_SERVER;
        $server = $this->servers->get($serverName);
        $config = $this->configByServer[$serverName] ?? new ServerDocumentConfig();

        $types = $this->typesFor($serverName, $server);

        return new ServerMetadata(
            title: $config->title ?? $this->defaultTitle($serverName),
            version: $config->version ?? '1.0.0',
            description: $config->description,
            contact: $config->contact,
            license: $config->license,
            servers: $config->servers !== [] ? $config->servers : $this->defaultServers($server),
            jsonApiVersion: $server->jsonApiVersion(),
            tags: $this->tagDefinitions($config, $types),
            securitySchemes: $config->securitySchemes,
            defaultSecurity: $config->defaultSecurity,
            externalDocs: $config->externalDocs,
            types: $types,
            atomicOperations: $this->atomicOperations(),
        );
    }

    /**
     * The **combined** OpenAPI metadata spanning every declared server in one document
     * (design D5, §10) — the source for `multi_server: combined`. It unions every
     * server's types (asserting non-colliding JSON:API types across servers, since one
     * document cannot carry two components for the same type), concatenates the
     * advertised base URIs, and dedupes the tag definitions and security schemes.
     *
     * The `info` block (title / version / description / contact / license / external
     * docs) and the document-level default security come from the `default` server's
     * config — there is one combined document, so it carries one info block; the
     * default server's config is the document-wide config in the single-server-optimized
     * design. The `jsonapi` version is the default server's.
     *
     * @throws \LogicException when two servers declare the same JSON:API type (the
     *                         combined document would need two components for one type)
     */
    public function combined(): ServerMetadataInterface
    {
        $defaultConfig = $this->configByServer[ServerProvider::DEFAULT_SERVER] ?? new ServerDocumentConfig();
        $defaultServer = $this->servers->get(ServerProvider::DEFAULT_SERVER);

        $types = [];
        $typeNames = [];
        $advertised = [];
        $securitySchemes = [];
        foreach ($this->serverNames() as $serverName) {
            $server = $this->servers->get($serverName);
            $config = $this->configByServer[$serverName] ?? new ServerDocumentConfig();

            foreach ($this->typesFor($serverName, $server) as $type) {
                if (\in_array($type->type(), $typeNames, true)) {
                    throw new \LogicException(\sprintf(
                        'Cannot build a combined OpenAPI document: the JSON:API type "%s" is declared on more than one server. Combined mode requires non-colliding types across servers.',
                        $type->type(),
                    ));
                }

                $types[] = $type;
                $typeNames[] = $type->type();
            }

            foreach (($config->servers !== [] ? $config->servers : $this->defaultServers($server)) as $advertisedServer) {
                $advertised[$advertisedServer->url] ??= $advertisedServer;
            }

            foreach ($config->securitySchemes as $name => $scheme) {
                $securitySchemes[$name] ??= $scheme;
            }
        }

        return new ServerMetadata(
            title: $defaultConfig->title ?? $this->defaultTitle(ServerProvider::DEFAULT_SERVER),
            version: $defaultConfig->version ?? '1.0.0',
            description: $defaultConfig->description,
            contact: $defaultConfig->contact,
            license: $defaultConfig->license,
            servers: \array_values($advertised),
            jsonApiVersion: $defaultServer->jsonApiVersion(),
            tags: $this->tagDefinitions($defaultConfig, $types),
            securitySchemes: $securitySchemes,
            defaultSecurity: $defaultConfig->defaultSecurity,
            externalDocs: $defaultConfig->externalDocs,
            types: $types,
            atomicOperations: $this->atomicOperations(),
        );
    }

    /**
     * The Atomic Operations extension endpoint metadata, or `null` when the extension
     * is globally disabled (`json_api.atomic_operations.enabled`). The extension is a
     * single global flag but the endpoint exists per server, so every server's
     * document (and the combined document) carries the same metadata when enabled —
     * mirroring the {@see \haddowg\JsonApiBundle\Routing\JsonApiRouteLoader}, which
     * emits one `POST {path}` per server.
     *
     * The atomic operation carries no per-endpoint security of its own (empty
     * `security()`), so core's projector falls back to the document-level default —
     * the same default-security modelling the rest of the document uses.
     */
    private function atomicOperations(): ?AtomicOperationsMetadata
    {
        if (!$this->atomicEnabled) {
            return null;
        }

        return new AtomicOperationsMetadata($this->atomicPath);
    }

    /**
     * Builds the type metadata list for one server, in route-descriptor order.
     *
     * @return list<TypeMetadataInterface>
     */
    private function typesFor(string $serverName, Server $server): array
    {
        $types = [];
        foreach ($this->descriptors->forServer($serverName) as $type => $descriptor) {
            if ($type === '') {
                continue;
            }

            $types[] = $this->buildType($server, $serverName, $type, $descriptor);
        }

        return $types;
    }

    /**
     * The declared server names, in a stable order (`default` first), drawn from the
     * route descriptors — the same per-server type source `forServer()` reads.
     *
     * @return list<string>
     */
    private function serverNames(): array
    {
        $names = $this->descriptors->serverNames();
        $ordered = \in_array(ServerProvider::DEFAULT_SERVER, $names, true)
            ? [ServerProvider::DEFAULT_SERVER]
            : [];
        foreach ($names as $name) {
            if ($name !== ServerProvider::DEFAULT_SERVER) {
                $ordered[] = $name;
            }
        }

        return $ordered;
    }

    /**
     * Assembles one type's metadata from its route descriptor + the live registry.
     *
     * @param array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>} $descriptor
     */
    private function buildType(Server $server, string $serverName, string $type, array $descriptor): TypeMetadata
    {
        $resource = $this->types->resourceFor($server, $type);
        $serverDefaultPaginator = $server->defaultPaginator();

        $relations = [];
        foreach ($this->types->relationsFor($server, $type) as $relation) {
            $relations[] = $this->buildRelation($server, $relation, $serverDefaultPaginator);
        }

        $operations = $this->operations($descriptor['operations']);

        $actions = [];
        foreach ($this->actions->forServerType($serverName, $type) as $action) {
            $actions[] = new ActionMetadata($this->withResolvedTags($action, $descriptor['tags'], $type));
        }

        $paginatorKind = $resource !== null
            ? $this->paginatorKinds->resolve($resource->pagination($serverDefaultPaginator))
            : PaginatorKind::None;

        return new TypeMetadata(
            type: $type,
            uriType: $descriptor['uriType'],
            hasFields: $resource !== null,
            fields: $resource !== null ? \array_values($resource->fields()) : [],
            relations: $relations,
            operations: $operations,
            securedOperations: $this->securedOperations($type, $operations),
            allowsClientId: $this->idEncoders->allowsClientIdFor($type),
            requiresClientId: $this->idEncoders->requiresClientIdFor($type),
            // The type's {id} route requirement (the un-anchored regex fragment a
            // uuid()/ulid()/numeric()/pattern()/matchAs() id declares), or null for an
            // unconstrained id (any non-empty string). Core's OperationProjector anchors
            // it onto the OAS {id} parameter as `^(?:<fragment>)$`.
            idPattern: $this->idEncoders->routePatternFor($type),
            paginatorKind: $paginatorKind,
            countable: $resource?->isCountable() ?? false,
            filters: $resource !== null ? \array_values($resource->filters()) : [],
            // allSorts(), not sorts(): the runtime accepts the field-derived sortables
            // (every `->sortable()` field) UNION the explicit sorts() overrides, so the
            // document must enumerate the same full set — otherwise a resource that
            // relies on `->sortable()` fields (the common case, no sorts() override)
            // would emit no `sort` parameter while `?sort=field` works.
            sorts: $resource !== null ? \array_values($resource->allSorts()) : [],
            actions: $actions,
            tags: $this->typeTags($descriptor['tags'], $type),
            // The OpenAPI description overrides (bundle ADR 0092), each resolved with
            // precedence resource method hook -> attribute override -> null (the
            // projector then supplies the generated default). A standalone serializer
            // has no resource and serves no CRUD operations, so both are naturally
            // empty for it.
            description: $resource?->getDescription() ?? $this->descriptions?->descriptionFor($type),
            operationDescriptions: $this->operationDescriptions($resource, $type),
            includablePaths: $resource !== null ? $this->includePaths->pathsFor($server, $type) : [],
        );
    }

    /**
     * The per-CRUD-operation OpenAPI description overrides for `$type`, keyed by
     * {@see OperationType::value}, each resolved with precedence: the resource's
     * {@see \haddowg\JsonApi\Resource\AbstractResource::describeOperation()} method hook,
     * then the `#[AsJsonApiResource(operationDescriptions:)]` attribute override, then
     * `null` (the projector emits the generated default). Only the five CRUD operations
     * carry a description; relationship/related operations are described via the
     * relation's own `describedAs()`.
     *
     * @return array<string, ?string>
     */
    private function operationDescriptions(?AbstractResource $resource, string $type): array
    {
        $descriptions = [];
        foreach (OperationType::cases() as $operation) {
            $value = $resource?->describeOperation($operation)
                ?? $this->descriptions?->operationDescriptionFor($type, $operation);
            if ($value !== null) {
                $descriptions[$operation->value] = $value;
            }
        }

        return $descriptions;
    }

    private function buildRelation(Server $server, RelationInterface $relation, ?PaginatorInterface $serverDefault): RelationMetadata
    {
        // Only a to-many relation has a related-collection to paginate; a to-one is
        // always PaginatorKind::None (the contract's rule). For a to-many, the
        // resolved paginator rides core's relation → related-resource → server-default
        // fallback chain — passing the server default as the fallback matches what a
        // render would resolve. (core's pagination() returns the fallback even for a
        // to-one, so the cardinality guard lives here.)
        $kind = $relation->isToMany()
            ? $this->paginatorKinds->resolve($relation->pagination($serverDefault))
            : PaginatorKind::None;

        return new RelationMetadata(
            $relation,
            $kind,
            $this->includePaths->relatedPathsFor($server, $relation),
        );
    }

    /**
     * Maps the descriptor's bundle {@see Operation} value strings to core
     * {@see OperationType} (the two enums share backing values), dropping any unknown value.
     *
     * @param list<string> $operations
     *
     * @return list<OperationType>
     */
    private function operations(array $operations): array
    {
        $mapped = [];
        foreach ($operations as $value) {
            $operation = Operation::tryFrom($value);
            if ($operation === null) {
                continue;
            }

            $type = OperationType::tryFrom($operation->value);
            if ($type !== null && !\in_array($type, $mapped, true)) {
                $mapped[] = $type;
            }
        }

        return $mapped;
    }

    /**
     * The subset of `$operations` carrying a security expression (design §4.6/D8),
     * resolved from the {@see ResourceSecurityRegistry} exactly as the subscriber
     * enforces it: create/update/delete gate their like-named operation; read gates
     * **only** FetchOne (there is no collection-read gate). Empty when the registry
     * is absent (no `symfony/security-core`) or the type declared no security.
     *
     * @param list<OperationType> $operations
     *
     * @return list<OperationType>
     */
    private function securedOperations(string $type, array $operations): array
    {
        $security = $this->security?->securityFor($type);
        if ($security === null) {
            return [];
        }

        $secured = [];
        foreach ($this->securedMap($security) as $operationType) {
            if (\in_array($operationType, $operations, true) && !\in_array($operationType, $secured, true)) {
                $secured[] = $operationType;
            }
        }

        return $secured;
    }

    /**
     * The operations a resolved {@see ResourceSecurity} gates, in operation order —
     * the projection of the per-operation expressions onto {@see OperationType}.
     *
     * @return list<OperationType>
     */
    private function securedMap(ResourceSecurity $security): array
    {
        $secured = [];
        if ($security->forCreate() !== null) {
            $secured[] = OperationType::Create;
        }
        if ($security->forUpdate() !== null) {
            $secured[] = OperationType::Update;
        }
        if ($security->forDelete() !== null) {
            $secured[] = OperationType::Delete;
        }
        // Read security gates the single-resource read only (AfterFetchOneEvent);
        // there is no collection-read hook, so FetchCollection is never secured here.
        if ($security->forRead() !== null) {
            $secured[] = OperationType::FetchOne;
        }

        return $secured;
    }

    /**
     * The OpenAPI tag refs for a type: the explicit descriptor refs, else the
     * humanized-type default (design §4.7).
     *
     * @param list<string> $explicit
     *
     * @return list<string>
     */
    private function typeTags(array $explicit, string $type): array
    {
        return $explicit !== [] ? $explicit : [$this->tagNames->defaultFor($type)];
    }

    /**
     * Re-resolves an action's tag refs against the contract default (design §4.7):
     * the descriptor already inherited the mount type's *explicit* resource tags at
     * compile time, but a resource that declared none left the action's tags empty —
     * so an action with empty tags inherits the same humanized-type default the
     * resource gets. Returns the descriptor unchanged when it already carries tags.
     *
     * @param list<string> $resourceTags
     */
    private function withResolvedTags(ActionDescriptor $action, array $resourceTags, string $type): ActionDescriptor
    {
        if ($action->tags !== []) {
            return $action;
        }

        $tags = $this->typeTags($resourceTags, $type);

        return new ActionDescriptor(
            $action->type,
            $action->path,
            $action->methods,
            $action->scope,
            $action->input,
            $action->inputType,
            $action->outputType,
            $action->security,
            $action->handlerServiceId,
            $action->server,
            $tags,
        );
    }

    /**
     * The document-root tag definitions (design §4.7/D15): the config definitions
     * (authoritative, in config order) unioned with a name-only synthesized
     * {@see Tag} for every tag a type/action references but config did not define, in
     * discovery order.
     *
     * @param list<TypeMetadataInterface> $types
     *
     * @return list<Tag>
     */
    private function tagDefinitions(ServerDocumentConfig $config, array $types): array
    {
        $definitions = [];
        $seen = [];
        foreach ($config->tagDefinitions as $tag) {
            if (!\in_array($tag->name, $seen, true)) {
                $definitions[] = $tag;
                $seen[] = $tag->name;
            }
        }

        foreach ($this->referencedTagNames($types) as $name) {
            if (!\in_array($name, $seen, true)) {
                $definitions[] = new Tag($name);
                $seen[] = $name;
            }
        }

        return $definitions;
    }

    /**
     * Every tag name referenced by a type or its actions, in discovery order (type
     * order, then per-type the type's tags, then each action's tags).
     *
     * @param list<TypeMetadataInterface> $types
     *
     * @return list<string>
     */
    private function referencedTagNames(array $types): array
    {
        $names = [];
        foreach ($types as $type) {
            foreach ($type->tags() as $name) {
                if (!\in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            foreach ($type->actions() as $action) {
                foreach ($action->tags() as $name) {
                    if (!\in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @return list<\haddowg\JsonApi\OpenApi\Server>
     */
    private function defaultServers(Server $server): array
    {
        $baseUri = $server->baseUri();

        return $baseUri === '' ? [] : [new \haddowg\JsonApi\OpenApi\Server($baseUri)];
    }

    private function defaultTitle(string $serverName): string
    {
        return $serverName === ServerProvider::DEFAULT_SERVER
            ? 'JSON:API'
            : \sprintf('JSON:API (%s)', $serverName);
    }
}
