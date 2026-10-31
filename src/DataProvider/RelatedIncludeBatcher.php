<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\WindowedRelationshipLinkage;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Loads every relation a read renders data for through the provider, so `included`
 * and linkage `data` hold exactly what the related endpoint returns, and so includes
 * do not N+1 (bundle ADR 0035).
 *
 * It walks the document level by level, starting from the primary resources. At each
 * level it looks at every relation of the level's type:
 *
 *  - an **included** relation (explicit `?include` or a default include, within the
 *    root's depth cap and allowed paths) is loaded for the whole level in one
 *    {@see DataProviderInterface::fetchRelatedCollectionBatch()} call, and the walk
 *    descends into the loaded targets;
 *  - a relation that is not included but whose linkage `data` still renders (a
 *    `withData()` relation, a relation the load-state predicate reports as loaded, a
 *    Doctrine to-one, or one with no links to render instead) is loaded the same way
 *    when its provider scopes related reads, so its identifiers are scoped too.
 *
 * Loaded relations reach core out of band through the relationship-linkage seams
 * ({@see preload()} returns the map): a to-many's members, and a scoped to-one's target
 * or none. Nothing is written onto the model.
 *
 * Each relation loads through the provider that serves its related endpoint, routed by
 * {@see RelatedReadRouter}. Reading a relation straight off the model is allowed only
 * when that equals what the provider would load: the provider reports it unscoped
 * ({@see ScopesRelatedReadsInterface}), the author computes its value (an
 * `extractUsing()` closure, or a getter that is not an association, reported as
 * {@see RelatedBatch::computed()}), or no provider serves the related type.
 * Otherwise a relation that cannot be loaded through the provider (a polymorphic
 * to-many, or a column the provider cannot batch) throws a `\LogicException` rather than
 * render the unscoped value. A `MorphTo` target is checked against the provider of the
 * type it renders as. A `MorphTo` declared with `identifiedBy()` renders its linkage
 * from the stored pair. Its targets load by id through each type's provider when they
 * render, and for linkage alone only the guarded types load (bundle ADR 0035). {@see disable()} turns off only the optional
 * batching of unscoped relations.
 *
 * Metadata is resolved on the request's own server, so a type registered only on a
 * named server is walked like any other.
 *
 * It also runs the eager-load pass for `on()` flattened attributes (bundle ADR 0035):
 * the to-one chains a resource declares via
 * {@see DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()} are loaded segment by
 * segment under the same rules, so a flattened attribute reads a scoped target (or
 * `null`) and does not N+1. The eager set is never expanded into `included`.
 */
final class RelatedIncludeBatcher
{
    /**
     * Process-wide switch for batching relations that are not scoped. When false those
     * relations render lazily (the N+1 the query-budget witnesses reveal); a scoped
     * relation is still loaded through its provider.
     *
     * @internal a test/diagnostic seam, not part of any contract
     */
    private bool $enabled = true;

    /**
     * The objects the document being prepared already renders as primary data or
     * loaded through a provider, keyed by object id. A to-one linkage pointing at one
     * of them discloses nothing new, so it needs no scoped load.
     *
     * @var array<int, true>
     */
    private array $known = [];

    /**
     * The scoped to-one targets the walk supplies out of band, keyed by parent object id
     * then relation name.
     *
     * @var array<int, array<string, ToOneRelationshipLinkage>>
     */
    private array $toOne = [];

    /**
     * The Relationship Queries window pages already installed for the primary level.
     */
    private ?RelationshipLinkageInterface $windowed = null;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
        private readonly ServerProvider $servers,
        private readonly RelatedReadRouter $router,
        private readonly IdentifiedTargetGuard $guard,
    ) {}

    /**
     * @internal
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * @internal
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Loads the relations the read of `$entities` (all of `$type`) renders data for,
     * and returns the to-many linkage to supply out-of-band, keyed by parent object and
     * relation name (`null` when there is none).
     *
     * `$server` is the server the request runs on. When omitted it is resolved from the
     * request's `_jsonapi_server` attribute. `$windowed` supplies the Relationship
     * Queries pages already windowed for the primary resources: those pages are what
     * renders, so the walk descends into them instead of loading the relation again.
     *
     * @param iterable<object> $entities
     *
     * @throws \LogicException when a relation whose data renders cannot be loaded through
     *                         its scoping provider
     */
    public function preload(
        iterable $entities,
        string $type,
        JsonApiRequestInterface $request,
        ?Server $server = null,
        ?RelationshipLinkageInterface $windowed = null,
    ): ?WindowedRelationshipLinkage {
        return $this->preloadGrouped([$type => $this->materialize($entities)], $request, $server, $windowed);
    }

    /**
     * {@see preload()} for primary resources of several types (a polymorphic related
     * page), keyed by type. Each type's tree follows its own include safeguards.
     *
     * @param array<string, list<object>> $entitiesByType
     *
     * @throws \LogicException when a relation whose data renders cannot be loaded through
     *                         its scoping provider
     */
    public function preloadGrouped(array $entitiesByType, JsonApiRequestInterface $request, ?Server $server = null, ?RelationshipLinkageInterface $windowed = null): ?WindowedRelationshipLinkage
    {
        $server ??= $this->servers->get($this->serverName($request));

        $this->windowed = $windowed;
        $this->known = [];
        $this->toOne = [];
        foreach ($entitiesByType as $entities) {
            $this->remember($entities);
        }

        $linkages = [];
        foreach ($entitiesByType as $type => $entities) {
            $entities = $this->unique($entities);
            if ($entities === [] || !$server->hasSerializerFor($type)) {
                continue;
            }

            // The include safeguards (bundle ADR 0036) are a property of the root
            // resource: its depth cap and allowed paths hold for the whole tree.
            $rootSerializer = $server->serializerFor($type);
            $maxDepth = $this->effectiveMaxDepth($rootSerializer, $server);
            $allowedPaths = $rootSerializer instanceof IncludeControlsInterface
                ? $rootSerializer->getAllowedIncludePaths()
                : null;

            $this->walkLevel($server, $entities, $type, $request, '', $maxDepth, $allowedPaths, $linkages);
        }

        return $this->linkage($linkages);
    }

    /**
     * The to-one target the related endpoint renders for `$relation` on `$parent`: the
     * one the parent holds, or `null` when the related type's scope hides it.
     *
     * @throws \LogicException when the relation cannot be loaded through its scoping provider
     */
    public function scopeToOne(Server $server, string $type, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): ?object
    {
        $this->reset();
        $linkages = [];

        return $this->loadRelation($server, $type, [$parent], $relation, $request, false, true, $linkages)[0] ?? null;
    }

    /**
     * The scoped linkage of `$relation` on a single `$parent` for its relationship
     * endpoint (a to-one, or a polymorphic relation), or `null` when it is not scoped.
     *
     * @throws \LogicException when the relation cannot be loaded through its scoping provider
     */
    public function scopeRelationship(Server $server, string $type, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): ?WindowedRelationshipLinkage
    {
        $this->reset();
        $linkages = [];
        $this->loadRelation($server, $type, [$parent], $relation, $request, false, false, $linkages);

        return $this->linkage($linkages);
    }

    /**
     * The scoped to-one targets of the `on()` chains `$entity` declares, for hydrating a
     * write: a flattened attribute is written onto the target its related endpoint
     * serves, and a hidden one is missing. The relations in `$skip` were just set from
     * the request body, so their new value is kept; the hops below them are still scoped.
     *
     * @param list<string> $skip
     *
     * @throws \LogicException when a hop cannot be loaded through its scoping provider
     */
    public function scopeEagerLoads(Server $server, string $type, object $entity, JsonApiRequestInterface $request, array $skip = []): ?WindowedRelationshipLinkage
    {
        $this->reset();
        if (!$server->hasSerializerFor($type)) {
            return null;
        }

        $loaded = [];
        foreach ($skip as $name) {
            $relation = $this->types->relationNamedIncludingHidden($server, $type, $name);
            if ($relation !== null) {
                $loaded[$name] = $this->readTargets([$entity], $relation, $request);
            }
        }

        $linkages = [];
        $this->eagerLoad($server, $server->serializerFor($type), [$entity], $type, $request, $loaded, $linkages);

        return $this->linkage($linkages);
    }

    private function reset(): void
    {
        $this->known = [];
        $this->toOne = [];
        $this->windowed = null;
    }

    /**
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     */
    private function linkage(array $linkages): ?WindowedRelationshipLinkage
    {
        return $linkages === [] && $this->toOne === [] ? null : new WindowedRelationshipLinkage($linkages, $this->toOne);
    }

    /**
     * One level of the walk: the eager pass, then every rendered relation of `$type`
     * over `$entities`, descending into the targets of the included ones.
     *
     * @param list<object>                                   $entities
     * @param list<string>|null                              $allowedPaths
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     */
    private function walkLevel(
        Server $server,
        array $entities,
        string $type,
        JsonApiRequestInterface $request,
        string $basePath,
        ?int $maxDepth,
        ?array $allowedPaths,
        array &$linkages,
    ): void {
        if ($entities === [] || !$server->hasSerializerFor($type)) {
            // A type the server cannot serialize renders no relationships at all.
            return;
        }

        $serializer = $server->serializerFor($type);

        // Relations already loaded at this level, keyed by name, holding their targets,
        // so an eager relation that is also included loads once.
        $loaded = [];

        $this->eagerLoad($server, $serializer, $entities, $type, $request, $loaded, $linkages);

        $relations = $this->types->readableRelationsFor($server, $type, $request);
        if ($relations === []) {
            return;
        }

        $resource = $this->types->resourceFor($server, $type);
        $defaults = $resource === null
            ? []
            : \array_flip($resource->getDefaultIncludedRelationships($entities[0]));

        foreach ($relations as $relation) {
            $name = $relation->name();
            $childPath = $basePath === '' ? $name : $basePath . '.' . $name;

            $windowedTargets = $basePath === '' ? $this->windowedTargets($entities, $relation, $request) : null;
            if ($windowedTargets !== null) {
                $loaded[$name] = $windowedTargets;
            }

            if ($this->isIncluded($relation, $request, $entities[0], $basePath, $childPath, $defaults, $maxDepth, $allowedPaths)) {
                $targets = $loaded[$name]
                    ?? $this->loadRelation($server, $type, $entities, $relation, $request, true, true, $linkages);
                $loaded[$name] = $targets;

                $this->descend($server, $relation, $targets, $request, $childPath, $maxDepth, $allowedPaths, $linkages);

                continue;
            }

            if (isset($loaded[$name]) || !$request->isIncludedField($type, $name)) {
                continue;
            }

            // Linkage only: load the parents whose relationship data renders anyway, and
            // leave the deferred ones (links only) untouched so the document keeps its shape.
            $rendering = \array_values(\array_filter(
                $entities,
                fn(object $entity): bool => $this->rendersLinkageData($server, $entity, $relation),
            ));
            if ($rendering !== []) {
                $this->loadRelation($server, $type, $rendering, $relation, $request, false, false, $linkages);
            }
        }
    }

    /**
     * The members of a primary-level to-many the Relationship Queries window already
     * supplies, or `null` when it is not windowed.
     *
     * @param list<object> $entities
     *
     * @return list<object>|null
     */
    private function windowedTargets(array $entities, RelationInterface $relation, JsonApiRequestInterface $request): ?array
    {
        if ($this->windowed === null || !$relation->isToMany()) {
            return null;
        }

        $targets = [];
        foreach ($entities as $entity) {
            $linkage = $this->windowed->linkageForRelationship($entity, $relation, $request);
            if ($linkage === null) {
                return null;
            }

            if (\is_iterable($linkage->data)) {
                foreach ($linkage->data as $member) {
                    if (\is_object($member)) {
                        $targets[] = $member;
                    }
                }
            }
        }

        $targets = $this->unique($targets);
        $this->remember($targets);

        return $targets;
    }

    /**
     * Loads `$relation` across `$entities` and returns the loaded targets (for the next
     * level), recording to-many linkage in `$linkages` and writing to-one values.
     *
     * `$included` marks a relation whose targets render in `included`; `$descends` marks
     * one whose targets the walk continues into. Either needs the targets back even when
     * the relation is left lazy.
     *
     * @param list<object>                                   $entities
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     *
     * @return list<object>
     */
    private function loadRelation(
        Server $server,
        string $type,
        array $entities,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        bool $included,
        bool $descends,
        array &$linkages,
    ): array {
        // Its linkage renders from the stored pair. A read of the targets loads them all;
        // otherwise only the guarded types load, so their pairs are checked.
        if ($relation instanceof MorphTo && $relation->storesIdentifiers()) {
            if ($included || $descends) {
                return $this->loadIdentified($server, $entities, $relation, $request);
            }

            $guarded = $this->guard->guardedTypes($relation, $this->serverName($request));
            if ($guarded !== []) {
                $this->loadIdentified($server, $entities, $relation, $request, $guarded);
            }

            return [];
        }

        // An extractUsing() relation's closure picks its candidates. Into a scoped type the
        // scope still decides which of them exist, so they are narrowed through the router
        // (bundle ADR 0035); otherwise they render as the closure returns them. A pivot
        // belongsToMany is not computed: the provider's pivot query is what it serves.
        if ($this->router->isComputed($relation)) {
            if (!$this->router->readsComputed($relation)) {
                return $descends ? $this->readTargets($entities, $relation, $request) : [];
            }

            return $this->supply($server, $type, $entities, $relation, true, [], $request, $linkages);
        }

        $relatedTypes = $relation->relatedTypes();
        $scoped = $this->isScoped($relation);

        if (\count($relatedTypes) !== 1) {
            if (!$relation->isToMany()) {
                return $this->loadPolymorphicToOne($server, $entities, $relation, $request, $descends);
            }

            if (!$scoped) {
                return $descends ? $this->readTargets($entities, $relation, $request) : [];
            }

            // A polymorphic to-many reaching a scoped type renders its members narrowed per
            // member type to the rows each scoped type's provider admits (bundle ADR 0035).
            $targets = [];
            foreach ($this->router->admitMembers($server, $entities, $relation, $request) as $index => $members) {
                $linkages[\spl_object_id($entities[$index])][$relation->name()] = new RelationshipLinkage($members);
                foreach ($members as $member) {
                    $targets[] = $member;
                }
            }
            $targets = $this->unique($targets);
            $this->remember($targets);

            return $targets;
        }

        $relatedType = $relatedTypes[0];

        // Core renders no data for a related type the server cannot serialize.
        if (!$server->hasSerializerFor($relatedType)) {
            return [];
        }

        if (!$this->providers->supportsType($relatedType) || (!$scoped && (!$included || !$this->enabled))) {
            return $descends ? $this->readTargets($entities, $relation, $request) : [];
        }

        // A scoped to-one that is empty, or points at an object this document already
        // renders, discloses nothing the scope would hide, so only the rest is loaded.
        $targets = [];
        if ($scoped && !$relation->isToMany()) {
            $pending = [];
            foreach ($entities as $entity) {
                $target = Accessor::get($entity, $relation->column() ?? $relation->name());
                if (!\is_object($target)) {
                    continue;
                }
                if (isset($this->known[\spl_object_id($target)])) {
                    $targets[] = $target;

                    continue;
                }
                $pending[] = $entity;
            }

            $entities = $pending;
            if ($entities === []) {
                return $descends ? $this->unique($targets) : [];
            }
        }

        $batch = $this->router->batch($server, $type, $entities, $relation, $this->plainIncludeCriteria($request), $request);

        if (!$batch->isSupported()) {
            if ($scoped && !$batch->isComputed()) {
                throw $this->refused($type, $relation, 'its provider cannot batch-load it (not a single-identifier association)');
            }

            return $descends ? $this->unique([...$targets, ...$this->readTargets($entities, $relation, $request)]) : [];
        }

        return $this->fill($server, $type, $entities, $relation, $scoped, $targets, $batch, $linkages);
    }

    /**
     * Loads `$relation` for `$entities` through the router and supplies what it loaded.
     *
     * @param list<object>                                   $entities
     * @param list<object>                                   $targets
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     *
     * @return list<object>
     */
    private function supply(Server $server, string $type, array $entities, RelationInterface $relation, bool $scoped, array $targets, JsonApiRequestInterface $request, array &$linkages): array
    {
        $batch = $this->router->batch($server, $type, $entities, $relation, $this->plainIncludeCriteria($request), $request);

        return $this->fill($server, $type, $entities, $relation, $scoped, $targets, $batch, $linkages);
    }

    /**
     * Supplies a loaded batch to core: each parent's to-many linkage, or its to-one
     * target when the relation is scoped.
     *
     * @param list<object>                                   $entities
     * @param list<object>                                   $targets
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     *
     * @return list<object>
     */
    private function fill(Server $server, string $type, array $entities, RelationInterface $relation, bool $scoped, array $targets, RelatedBatch $batch, array &$linkages): array
    {
        $serializer = $server->serializerFor($type);

        foreach ($entities as $entity) {
            $items = $this->itemsOf($batch->for($serializer->getId($entity)));

            if ($relation->isToMany()) {
                $linkages[\spl_object_id($entity)][$relation->name()] = new RelationshipLinkage($items);
            } elseif ($scoped) {
                $this->toOne[\spl_object_id($entity)][$relation->name()] = isset($items[0])
                    ? ToOneRelationshipLinkage::to($items[0])
                    : ToOneRelationshipLinkage::none();
            }

            foreach ($items as $item) {
                $targets[] = $item;
            }
        }

        $targets = $this->unique($targets);
        $this->remember($targets);

        return $targets;
    }

    /**
     * Scopes a polymorphic to-one per parent: each target is checked against the
     * provider of the type it renders as, which hides it (`none()`) when its scope
     * excludes it.
     *
     * @param list<object> $entities
     *
     * @return list<object>
     */
    private function loadPolymorphicToOne(Server $server, array $entities, RelationInterface $relation, JsonApiRequestInterface $request, bool $descends): array
    {
        $targets = [];
        foreach ($entities as $entity) {
            $target = $relation->readValue($entity, $request);
            if (!\is_object($target)) {
                continue;
            }

            $serializer = $relation->resolveSerializer($target, $server);
            if ($serializer === null) {
                continue;
            }

            $targetType = $serializer->getType($target);
            if (isset($this->known[\spl_object_id($target)]) || !$this->router->isScopedType($targetType, $relation)) {
                $targets[] = $target;

                continue;
            }

            $visible = $this->providers->forType($targetType)
                ->relatedToOneMatches($targetType, $target, $relation, $this->plainIncludeCriteria($request), $request);
            $this->toOne[\spl_object_id($entity)][$relation->name()] = $visible
                ? ToOneRelationshipLinkage::to($target)
                : ToOneRelationshipLinkage::none();
            if ($visible) {
                $targets[] = $target;
            }
        }

        $targets = $this->unique($targets);
        $this->remember($targets);

        return $descends ? $targets : [];
    }

    /**
     * Loads the targets of a `MorphTo` declared with `identifiedBy()` from the parents'
     * stored pairs, one query per type through that type's provider, and supplies each
     * parent's target, or none when the provider's scope hides it (bundle ADR 0035).
     * With `$types`, only those types load and the rest keep their pairs. Otherwise a
     * type no provider serves is left to core, which reads the relation's usual value.
     *
     * @param list<object>      $entities
     * @param list<string>|null $types
     *
     * @return list<object>
     */
    private function loadIdentified(Server $server, array $entities, MorphTo $relation, JsonApiRequestInterface $request, ?array $types = null): array
    {
        $targets = [];
        $unloaded = [];
        foreach ($this->router->identifiedTargets($server, $relation, $entities, $request, $types) as $index => $identified) {
            if ($identified === null) {
                continue;
            }

            if (!$identified['loaded']) {
                if ($types === null) {
                    $unloaded[] = $entities[$index];
                }

                continue;
            }

            $target = $identified['target'];
            $this->toOne[\spl_object_id($entities[$index])][$relation->name()] = $target !== null
                ? ToOneRelationshipLinkage::to($target)
                : ToOneRelationshipLinkage::none();
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        $targets = $this->unique([...$targets, ...($unloaded !== [] ? $this->readTargets($unloaded, $relation, $request) : [])]);
        $this->remember($targets);

        return $targets;
    }

    /**
     * Continues the walk into an included relation's targets, grouped by their type (a
     * polymorphic relation's targets span several).
     *
     * @param list<object>                                   $targets
     * @param list<string>|null                              $allowedPaths
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     */
    private function descend(
        Server $server,
        RelationInterface $relation,
        array $targets,
        JsonApiRequestInterface $request,
        string $childPath,
        ?int $maxDepth,
        ?array $allowedPaths,
        array &$linkages,
    ): void {
        foreach ($this->groupByType($server, $relation, $targets) as $targetType => $group) {
            $this->walkLevel($server, $group, $targetType, $request, $childPath, $maxDepth, $allowedPaths, $linkages);
        }
    }

    /**
     * The eager-load pass (bundle ADR 0035, load-not-render) for the `on()` chains the
     * serializer declares, walked segment by segment. A shared prefix loads once.
     *
     * @param list<object>                                   $entities
     * @param array<string, list<object>>                   &$loaded the level guard shared with the include walk
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     */
    private function eagerLoad(
        Server $server,
        SerializerInterface $serializer,
        array $entities,
        string $type,
        JsonApiRequestInterface $request,
        array &$loaded,
        array &$linkages,
    ): void {
        if (!$serializer instanceof DeclaresEagerLoadsInterface) {
            return;
        }

        $tree = $this->eagerTree($serializer->eagerLoadRelationshipPaths());
        if ($tree !== []) {
            $this->eagerLoadLevel($server, $entities, $type, $request, $tree, $loaded, $linkages);
        }
    }

    /**
     * One eager level. Every segment is a declared to-one (core's warm-up validation
     * guarantees it), loaded like an included relation but never rendered as one.
     *
     * @param list<object>                                   $entities
     * @param array<string, mixed>                           $tree
     * @param array<string, list<object>>                   &$loaded
     * @param array<int, array<string, RelationshipLinkage>> $linkages
     */
    private function eagerLoadLevel(
        Server $server,
        array $entities,
        string $type,
        JsonApiRequestInterface $request,
        array $tree,
        array &$loaded,
        array &$linkages,
    ): void {
        if ($entities === []) {
            return;
        }

        foreach ($tree as $relationName => $children) {
            $relation = $this->types->relationNamedIncludingHidden($server, $type, $relationName);
            if ($relation === null) {
                // Core's warm-up validation rejects an unknown segment.
                continue;
            }

            $children = $this->subTree($children);
            $hasChildren = $children !== [];

            $targets = $loaded[$relationName]
                ?? $this->loadRelation($server, $type, $entities, $relation, $request, true, $hasChildren, $linkages);
            $loaded[$relationName] = $targets;

            if (!$hasChildren || $targets === []) {
                continue;
            }

            foreach ($this->groupByType($server, $relation, $targets) as $nextType => $group) {
                $nextLoaded = [];
                $this->eagerLoadLevel($server, $group, $nextType, $request, $children, $nextLoaded, $linkages);
            }
        }
    }

    /**
     * Whether `$relation` is part of the rendered include tree at this level: includable
     * for this caller, named (or defaulted) at `$basePath`, within the root's depth cap
     * and allowed paths (bundle ADR 0036). Includability is read off the level's first
     * entity; the transformer stays the per-object authority.
     *
     * @param array<string, int> $defaults
     * @param list<string>|null  $allowedPaths
     */
    private function isIncluded(
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        object $representative,
        string $basePath,
        string $childPath,
        array $defaults,
        ?int $maxDepth,
        ?array $allowedPaths,
    ): bool {
        if (!$relation->isIncludableFor($request, $representative)) {
            return false;
        }

        if (!$request->isIncludedRelationship($basePath, $relation->name(), $defaults)) {
            return false;
        }

        if ($maxDepth !== null && (\substr_count($childPath, '.') + 1) > $maxDepth) {
            return false;
        }

        return $allowedPaths === null || \in_array($childPath, $allowedPaths, true);
    }

    /**
     * Whether core renders `data` for `$relation` on `$entity` when it is not included.
     * Mirrors core's deferral: only a lazy relation with links to render, whose
     * load-state predicate reports it unloaded, renders links only.
     */
    private function rendersLinkageData(Server $server, object $entity, RelationInterface $relation): bool
    {
        if (!$relation->emitsDataOnlyWhenLoaded() || !$relation->includesLinks()) {
            return true;
        }

        // No endpoint to link to leaves the relationship nothing to render but data.
        if (!$relation->exposesRelatedEndpoint() && !$relation->exposesRelationshipEndpoint()) {
            return true;
        }

        $loadState = $server->relationshipLoadState();

        return $loadState === null || $loadState->isRelationshipLoaded($entity, $relation);
    }

    private function isScoped(RelationInterface $relation): bool
    {
        return $this->router->isScoped($relation);
    }

    /**
     * Reads the relation's current value off each entity (the lazy path), flattened to
     * the target objects.
     *
     * @param list<object> $entities
     *
     * @return list<object>
     */
    private function readTargets(array $entities, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $targets = [];
        foreach ($entities as $entity) {
            $value = $relation->readValue($entity, $request);
            if (\is_object($value) && !\is_iterable($value)) {
                $targets[] = $value;

                continue;
            }

            if (\is_iterable($value)) {
                foreach ($value as $member) {
                    if (\is_object($member)) {
                        $targets[] = $member;
                    }
                }
            }
        }

        return $this->unique($targets);
    }

    /**
     * Groups targets by the JSON:API type they render as.
     *
     * @param list<object> $targets
     *
     * @return array<string, list<object>>
     */
    private function groupByType(Server $server, RelationInterface $relation, array $targets): array
    {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) === 1) {
            return $targets === [] ? [] : [$relatedTypes[0] => $targets];
        }

        $groups = [];
        foreach ($targets as $target) {
            $serializer = $relation->resolveSerializer($target, $server);
            if ($serializer !== null) {
                $groups[$serializer->getType($target)][] = $target;
            }
        }

        return $groups;
    }

    private function refused(string $type, RelationInterface $relation, string $reason): \LogicException
    {
        return new \LogicException(\sprintf(
            'Relationship "%s" of type "%s" renders data, and its related type is scoped by its data provider, but %s. Rendering it from the model would bypass that scope. Give it an extractUsing() that applies the scope, make it lazy (no withData()) and do not include it, or hide it.',
            $relation->name(),
            $type,
            $reason,
        ));
    }

    /**
     * Empty filters/sorts and no window: each parent's whole related set, scoped by the
     * provider.
     */
    private function plainIncludeCriteria(JsonApiRequestInterface $request): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters(
                fields: [],
                includes: [],
                sort: [],
                filter: [],
                pagination: $request->getPagination(),
            ),
        );
    }

    /**
     * Folds dotted eager paths into a prefix tree, so `['author.country', 'author.city']`
     * becomes `['author' => ['country' => [], 'city' => []]]`.
     *
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    private function eagerTree(array $paths): array
    {
        /** @var array<string, mixed> $tree */
        $tree = [];
        foreach ($paths as $path) {
            $cursor = &$tree;
            foreach (\explode('.', $path) as $segment) {
                if ($segment === '') {
                    continue;
                }
                if (!\is_array($cursor[$segment] ?? null)) {
                    $cursor[$segment] = [];
                }
                $cursor = &$cursor[$segment];
            }
            unset($cursor);
        }

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private function subTree(mixed $node): array
    {
        $tree = [];
        if (\is_array($node)) {
            foreach ($node as $name => $children) {
                if (\is_string($name)) {
                    $tree[$name] = $children;
                }
            }
        }

        return $tree;
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return list<object>
     */
    private function itemsOf(CollectionResult $result): array
    {
        $items = \is_array($result->items)
            ? \array_values($result->items)
            : \iterator_to_array($result->items, false);

        return \array_values(\array_filter($items, static fn(mixed $item): bool => \is_object($item)));
    }

    /**
     * The root's include-depth cap (its own override, else the server default), with a
     * non-positive value meaning unlimited.
     */
    private function effectiveMaxDepth(SerializerInterface $serializer, Server $server): ?int
    {
        $depth = ($serializer instanceof IncludeControlsInterface ? $serializer->maxIncludeDepth() : null)
            ?? $server->maxIncludeDepth();

        return ($depth !== null && $depth <= 0) ? null : $depth;
    }

    private function serverName(JsonApiRequestInterface $request): string
    {
        $name = $request->getAttribute('_jsonapi_server');

        return \is_string($name) && $name !== '' ? $name : ServerProvider::DEFAULT_SERVER;
    }

    /**
     * @param iterable<object> $entities
     *
     * @return list<object>
     */
    private function materialize(iterable $entities): array
    {
        return \is_array($entities) ? \array_values($entities) : \iterator_to_array($entities, false);
    }

    /**
     * @param list<object> $objects
     */
    private function remember(array $objects): void
    {
        foreach ($objects as $object) {
            $this->known[\spl_object_id($object)] = true;
        }
    }

    /**
     * @param list<object> $objects
     *
     * @return list<object>
     */
    private function unique(array $objects): array
    {
        $unique = [];
        foreach ($objects as $object) {
            $unique[\spl_object_id($object)] = $object;
        }

        return \array_values($unique);
    }
}
