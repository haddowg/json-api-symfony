<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Laravel-style batch eager-loading of a read's effective `?include` tree, so an
 * included relationship does not N+1: one batched query loads a relation for every
 * source entity at a level, then its loaded targets seed the next level. The
 * successor to the retired Doctrine `IncludePreloader` + `shipmonk/doctrine-entity-preloader`
 * (bundle ADR 0062): the include-decision + the three ADR-0037 safeguards + the
 * recursion are lifted UNCHANGED, but the per-relation load now runs through the
 * provider SPI's {@see DataProviderInterface::fetchRelatedCollectionBatch()} +
 * {@see Accessor::set} write-back rather than a Doctrine-specific preloader — so the
 * orchestrator is **provider-agnostic** and batches includes for EVERY batching
 * provider (the Doctrine reference AND the in-memory witness), per level, with no
 * per-provider branching.
 *
 * Each level loads in PLAIN-INCLUDE (fast-path) mode: an empty filter/sort criteria
 * with a NULL window, so the batch loads the WHOLE related set per parent
 * (`WHERE fk IN`, no per-parent slice) — byte-for-byte the rows the preloader
 * loaded. The write-back wraps a to-many's loaded set to match the column's
 * container (a Doctrine `Collection` property cannot take a raw array) and writes a
 * to-one's single target (`items[0] ?? null`) onto the to-one column. The recursion
 * then descends on the flat list of loaded targets, threading the root-resolved
 * safeguards unchanged.
 *
 * Batching is a pure optimization: a relation/provider that cannot batch falls back
 * to a LAZY load and the rendered document is identical. The orchestrator simply
 * skips such a relation (the serializer reads it off the parent on demand):
 *
 *  - a polymorphic relation (more than one related type — no single target class to
 *    batch) is skipped here, so even the in-memory provider (which CAN read a mixed
 *    set) is left lazy, keeping includes byte-identical with the Doctrine boundary;
 *  - a related type with no batching provider is skipped;
 *  - a relation the provider cannot batch (a computed/`extractUsing` column that is
 *    not a real association, or a composite-id target) is detected INSIDE the
 *    provider's batch, which returns an empty {@see RelatedBatch} — so the write-back
 *    is a no-op and the relation renders lazily (bundle ADR 0062).
 *
 * It honours the three include safeguards (bundle ADR 0037), resolved once against
 * the ROOT resource and threaded unchanged through the recursion: it never batches a
 * non-includable relation ({@see RelationInterface::isIncludable()} `=== false`),
 * never descends past the effective max include depth (the root resource's
 * {@see IncludeControlsInterface::maxIncludeDepth()} override, else the server
 * default), and never batches a path the root resource's
 * {@see IncludeControlsInterface::getAllowedIncludePaths()} excludes. A request that
 * violates any of these `400`s in core before this runs, so this is belt and braces —
 * and the recursion is bounded by the same effective depth so a mutual default-include
 * cycle terminates here too.
 *
 * The {@see Server} (and so the per-type metadata) is resolved through the
 * {@see ServerProvider}'s default server — the single-server-optimized common case; a
 * type registered only on a non-default server resolves no resource here and renders
 * lazily.
 */
final class RelatedIncludeBatcher
{
    /**
     * Process-wide on/off for include batching — the disable seam the conformance
     * witness toggles to prove the rendered document is identical with and without
     * batching (and that disabling it reveals the N+1). When false {@see preload()}
     * early-returns, so every relation renders lazily. Not readonly: the witness
     * flips it at runtime.
     *
     * @internal a test/diagnostic seam, not part of any contract; it disables ALL
     *           types' batching for the process (the per-type preloader instance it
     *           replaced only ever disabled one type, so the witness sees the same
     *           lazy behaviour)
     */
    private bool $enabled = true;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
        private readonly ServerProvider $servers,
    ) {}

    /**
     * Disables include batching process-wide (the witness's cold-read seam). Batching
     * is a pure optimization, so turning it off only changes HOW includes are loaded
     * (lazily), never WHAT is rendered.
     *
     * @internal
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Re-enables include batching process-wide (restores the default).
     *
     * @internal
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Batch-loads the effective include tree rooted at the `$type` entities in
     * `$entities`, recursing one level per `.`-separated include segment. A no-op when
     * batching is disabled, there are no entities, the type has no resource (a bare
     * pair declares no relations) or no relation at this level is included.
     *
     * The include safeguards (bundle ADR 0037) are resolved once against the root
     * resource — the effective depth cap and the allowed-include-paths whitelist — and
     * threaded unchanged through the recursion, so the whole nested tree obeys the
     * root's policy.
     *
     * @param iterable<object>  $entities
     * @param ?int              $maxDepth     the effective include-depth cap resolved at the root (null = unlimited)
     * @param list<string>|null $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     */
    public function preload(
        iterable $entities,
        string $type,
        JsonApiRequestInterface $request,
        string $basePath = '',
        ?int $maxDepth = null,
        ?array $allowedPaths = null,
        bool $rootResolved = false,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $entities = $this->materialize($entities);
        if ($entities === []) {
            return;
        }

        if (!$this->providers->supportsType($type)) {
            return;
        }

        $server = $this->servers->get();
        $relations = $this->types->relationsFor($server, $type);
        if ($relations === []) {
            return;
        }

        // Resolve the root-scoped safeguards once: the effective depth cap (the
        // primary resource's maxIncludeDepth() override ?? the server default,
        // normalised so <=0 is unlimited) and its allowed-include-paths whitelist.
        // Both then ride the recursion unchanged — they are a property of the root.
        if (!$rootResolved) {
            $serializer = $server->serializerFor($type);
            $maxDepth = $this->effectiveMaxDepth($serializer, $server);
            $allowedPaths = $serializer instanceof IncludeControlsInterface
                ? $serializer->getAllowedIncludePaths()
                : null;
            $rootResolved = true;
        }

        // The default includes are per-type-uniform here: read off the first entity as
        // the representative (getDefaultIncludedRelationships is a type-level
        // declaration, not a per-object one in the reference resources).
        $resource = $this->types->resourceFor($server, $type);
        $defaults = $resource === null
            ? []
            : \array_flip($resource->getDefaultIncludedRelationships($entities[0]));

        foreach ($relations as $relation) {
            // Capability A: a non-includable relation can never be part of the rendered
            // tree (core 400s a request that names it, and excludes it from the default
            // cascade), so it is never batched.
            if (!$relation->isIncludable()) {
                continue;
            }

            if (!$request->isIncludedRelationship($basePath, $relation->name(), $defaults)) {
                continue;
            }

            $childPath = $basePath === '' ? $relation->name() : $basePath . '.' . $relation->name();

            // Capability B: this relation sits at depth = the segment count of its path;
            // stop the descent once it would exceed the effective cap (this is also what
            // halts a mutual default-include cycle here).
            if ($maxDepth !== null && (\substr_count($childPath, '.') + 1) > $maxDepth) {
                continue;
            }

            // Capability C: when the root restricts include paths, only batch a path it
            // permits.
            if ($allowedPaths !== null && !\in_array($childPath, $allowedPaths, true)) {
                continue;
            }

            $this->loadRelation($server, $entities, $type, $relation, $request, $childPath, $maxDepth, $allowedPaths);
        }
    }

    /**
     * Batch-loads a single included relation across `$entities` through the related
     * type's provider, writes each parent's result back onto the relation column, then
     * recurses into the loaded targets for nested includes. A polymorphic relation, or
     * a related type with no batching provider, is left to a lazy load (the orchestrator
     * simply does not batch it).
     *
     * @param list<object>      $entities
     * @param ?int              $maxDepth     the effective include-depth cap (null = unlimited)
     * @param list<string>|null $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     */
    private function loadRelation(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        string $childPath,
        ?int $maxDepth,
        ?array $allowedPaths,
    ): void {
        // A polymorphic relation has more than one related type, so there is no single
        // target entity class to batch against — render it lazily. Skipped HERE (not in
        // the provider) so even the in-memory provider, which CAN read a mixed set, is
        // left lazy, keeping includes byte-identical with the Doctrine boundary.
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            return;
        }

        $relatedType = $relatedTypes[0];

        // No batching provider for the related type (e.g. a custom provider that does
        // not implement the SPI's batch for this type): render lazily.
        if (!$this->providers->supportsType($relatedType)) {
            return;
        }

        $column = $relation->column() ?? $relation->name();

        // Snapshot each parent's column value before the batch reads it, so a to-many
        // write-back can restore the column's container type (a Doctrine Collection
        // property cannot take a raw array). Keyed by object id.
        $snapshots = [];
        foreach ($entities as $entity) {
            $snapshots[\spl_object_id($entity)] = Accessor::get($entity, $column);
        }

        // Drive the batch through the PRIMARY type's provider in PLAIN-INCLUDE
        // (fast-path) mode: an empty filter/sort criteria with a NULL window loads the
        // WHOLE related set per parent (WHERE fk IN, no slice) — byte-for-byte the rows
        // the retired preloader loaded.
        $batch = $this->providers->forType($type)
            ->fetchRelatedCollectionBatch($type, $entities, $relation, $this->plainIncludeCriteria($request), $request);

        $serializer = $server->serializerFor($type);

        $targets = [];
        foreach ($entities as $entity) {
            $result = $batch->for($serializer->getId($entity));
            $loaded = $this->itemsOf($result);
            $this->writeBack($entity, $relation, $column, $loaded, $snapshots[\spl_object_id($entity)] ?? null);
            foreach ($loaded as $target) {
                $targets[] = $target;
            }
        }

        // Thread the root-scoped safeguards into the next level unchanged: the cap and
        // whitelist are a property of the root resource, not of each hop.
        $this->preload($targets, $relatedType, $request, $childPath, $maxDepth, $allowedPaths, rootResolved: true);
    }

    /**
     * Writes a parent's batched-loaded related value back onto its relation column. A
     * to-many writes the loaded list wrapped to match the column's container (the
     * snapshot carries the container type); a to-one writes `items[0] ?? null` onto the
     * to-one column — NEVER an array/CollectionResult, which would corrupt the to-one
     * render. An empty to-one batch (the target is null/scoped out) leaves the loaded
     * value `null`, matching the lazy result.
     *
     * @param list<object> $loaded
     */
    private function writeBack(object $entity, RelationInterface $relation, string $column, array $loaded, mixed $snapshot): void
    {
        if ($relation->isToMany()) {
            Accessor::set($entity, $column, $this->asContainer($loaded, $snapshot));

            return;
        }

        Accessor::set($entity, $column, $loaded[0] ?? null);
    }

    /**
     * The plain-include (fast-path) criteria: empty filters/sorts/defaultSort and a
     * NULL window, so {@see DataProviderInterface::fetchRelatedCollectionBatch()} loads
     * the WHOLE related set per parent with no slice — the byte-for-byte rows the
     * preloader loaded (bundle ADR 0062).
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
     * Materializes a {@see CollectionResult}'s items to a `list<object>`.
     *
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
     * The effective include-depth cap for a root `$serializer` on `$server`: the
     * resource's own {@see IncludeControlsInterface::maxIncludeDepth()} override (when
     * it implements the capability) `??` the server default
     * {@see Server::maxIncludeDepth()}, normalised so a non-positive value means
     * unlimited (`null`). Mirrors the transformer's resolution, so the recursion is
     * bounded by exactly the tree core would render.
     */
    private function effectiveMaxDepth(SerializerInterface $serializer, Server $server): ?int
    {
        $depth = ($serializer instanceof IncludeControlsInterface ? $serializer->maxIncludeDepth() : null)
            ?? $server->maxIncludeDepth();

        return ($depth !== null && $depth <= 0) ? null : $depth;
    }

    /**
     * Wraps a to-many's loaded list in the container the relation's column expects: a
     * Doctrine `Collection`-typed property cannot take a raw array, so when the column
     * held a {@see \Doctrine\Common\Collections\Collection} the list is wrapped in an
     * {@see \Doctrine\Common\Collections\ArrayCollection}; an array (the in-memory
     * model) keeps the plain list. The snapshot is the column's pre-batch value, so it
     * carries the container type to preserve.
     *
     * @param list<object> $items
     *
     * @return list<object>|\Doctrine\Common\Collections\Collection<int, object>
     */
    private function asContainer(array $items, mixed $snapshot): array|object
    {
        if (
            $snapshot instanceof \Doctrine\Common\Collections\Collection
            && \class_exists(\Doctrine\Common\Collections\ArrayCollection::class)
        ) {
            return new \Doctrine\Common\Collections\ArrayCollection($items);
        }

        return $items;
    }

    /**
     * Materializes `$entities` to a `list<object>`.
     *
     * @param iterable<object> $entities
     *
     * @return list<object>
     */
    private function materialize(iterable $entities): array
    {
        if (\is_array($entities)) {
            return \array_values($entities);
        }

        return \iterator_to_array($entities, false);
    }
}
