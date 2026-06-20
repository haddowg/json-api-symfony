<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface;
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
 *
 * In addition to the `?include` tree it runs an **eager-load pass** (bundle ADR 0085):
 * the load-not-render to-one chains a resource declares via
 * {@see \haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()}
 * — the dedup set of every `on()` flattened attribute's backing relation chain — batch-loaded
 * onto each level through the SAME {@see DataProviderInterface::fetchRelatedCollectionBatch()}
 * seam, so a flattened read does not N+1. An `on()` chain MAY be **multi-hop**
 * (`'publisher.country'`): the pass walks it SEGMENT BY SEGMENT, batch-loading each level across
 * the targets the previous level loaded (the same level-walk / fan-out the `?include` tree
 * uses), so a multi-hop chain loads in O(depth) with no per-row N+1 at any level; a shared
 * prefix (`author.country` + `author.city`) loads its `author` once. The eager set is
 * author-declared and trusted, so it BYPASSES the client-include safeguards and is NEVER
 * expanded into `included` (rendering stays gated on the transformer's `isIncludedRelationship`),
 * and resolves its targets against the HIDDEN-INCLUSIVE declared-relation set (an `on()` backing
 * relation is idiomatically hidden). An overlap with `?include` (or a sibling eager path) loads
 * ONCE (a per-level "already loaded" guard that holds across levels).
 *
 * Every segment of every `on()` chain is a DECLARED to-one relation — guaranteed by core's
 * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator} (the bundle's
 * {@see \haddowg\JsonApiBundle\Serializer\EagerLoadWarmer}), which throws a developer-facing
 * `\LogicException` at **boot / container warm-up** (`cache:clear` / deploy, never a runtime
 * 500) on an unknown segment (a typo) OR a to-many segment at any depth (`on()` flattens a
 * scalar from a to-one chain; a to-many is not flattenable — use `?include`). This pass
 * therefore runs over a validated, all-to-one declaration: there is no windowed-to-many
 * interaction and no rendering contradiction to guard against (eager-loading a to-one never
 * flips its linkage). A polymorphic / inventory-less segment that cannot be resolved to a
 * single next type is left lazy (the branch stops), exactly as the include walk leaves it.
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

        // The metadata is resolved through the default server (the single-server-optimized
        // common case). A type registered ONLY on a non-default server is unknown here, so
        // there is no serializer/resource to read an eager set or a relation tree from — it
        // renders lazily, exactly as before the eager pass existed (the original early-return
        // on an empty relation set covered this; the eager pass must not regress it).
        if (!$server->hasSerializerFor($type)) {
            return;
        }

        $relations = $this->types->relationsFor($server, $type);

        // The serializer is the eager-load + include-safeguard authority for this level.
        // Resolved unconditionally (NOT only when a relation is `?include`'d): a resource
        // with no rendered relationship can still declare eager loads — an `on()` attribute
        // flattens a HIDDEN to-one that never appears in `$relations`.
        $serializer = $server->serializerFor($type);

        // Resolve the root-scoped safeguards once: the effective depth cap (the
        // primary resource's maxIncludeDepth() override ?? the server default,
        // normalised so <=0 is unlimited) and its allowed-include-paths whitelist.
        // Both then ride the recursion unchanged — they are a property of the root.
        if (!$rootResolved) {
            $maxDepth = $this->effectiveMaxDepth($serializer, $server);
            $allowedPaths = $serializer instanceof IncludeControlsInterface
                ? $serializer->getAllowedIncludePaths()
                : null;
            $rootResolved = true;
        }

        // Per-level "already loaded" guard, keyed by relation name: a relation eager-loaded
        // here (an `on()` attribute's backing relation chain) is not loaded a SECOND time when
        // the same relation is also `?include`'d — the include walk reuses the loaded value and
        // only recurses into it for nested includes.
        $loaded = [];

        // Eager-load pass FIRST (load-not-render): batch-load the to-one chains the resource
        // declares via DeclaresEagerLoadsInterface — every `on()` flattened attribute's backing
        // relation chain — so the flattened read does not N+1. The eager set is author-declared
        // and trusted, so it BYPASSES the client-include safeguards (depth cap / allowed-paths /
        // cannotBeIncluded) and is NEVER expanded into `included` (rendering stays gated on the
        // transformer's isIncludedRelationship — eager-loading changes only the query plan,
        // never the document).
        $this->eagerLoad($server, $serializer, $entities, $type, $request, $loaded);

        // No rendered relations at this level: the eager pass has run, so nothing remains.
        if ($relations === []) {
            return;
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
            // cascade), so it is never batched. Includability is request-aware (core
            // ADR 0079): a relation declared `cannotBeIncluded(fn)` resolves against
            // the inbound request and the representative entity, so a relation that is
            // non-includable for *this* caller isn't eagerly batched for it (matching
            // the 400 the transformer raises if the caller names it).
            //
            // Includability is resolved ONCE PER BATCH off the first entity as the
            // representative — this is an eager-load optimisation over a whole page, not
            // the rendering authority. The transformer remains the per-OBJECT authority,
            // so a model-VARYING includability predicate (includable for one entity in
            // the page but not another) is rendered correctly regardless: the batch
            // either skips the eager load (the relation degrades to a per-entity lazy
            // load at render) or eager-loads members the transformer then omits for the
            // entities that gate it. The wire document is identical either way; only the
            // query plan differs.
            if (!$relation->isIncludableFor($request, $entities[0])) {
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

            $this->loadRelation($server, $entities, $type, $relation, $request, $childPath, $maxDepth, $allowedPaths, $loaded);
        }
    }

    /**
     * The eager-load pass (bundle ADR 0085, load-not-render): batch-loads every to-one chain
     * the `$serializer` declares via
     * {@see DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()} — the dedup set of every
     * `on()` flattened attribute's backing relation chain — onto `$entities`, so the flattened
     * read does not N+1. Marks each top-level relation it loads in `$loaded` so the include walk
     * does not re-load an overlapping relation.
     *
     * An `on()` chain may be **multi-hop** (`on('publisher.country')`): the pass groups the
     * declared paths into a prefix tree once, then walks it **segment by segment**, batch-loading
     * each level across the targets the previous level loaded (mirroring the {@see loadRelation()}
     * `?include` fan-out — `WHERE fk IN` per level, no per-row N+1 at ANY depth) and following
     * each segment's relation to the next type via {@see RelationInterface::relatedTypes()}. A
     * shared prefix loads ONCE: the tree dedups `author.country` and `author.city` to a single
     * `author` load whose targets seed both branches. The per-level "already loaded" guard holds
     * across levels — each relation loads once per level, and an eager top-level relation already
     * loaded by `?include` is not reloaded.
     *
     * The eager set is author-declared and trusted, so it BYPASSES the client-include
     * safeguards (depth cap / allowed-paths / cannotBeIncluded) — none are consulted here.
     * It is NEVER expanded into `included`: the eager load writes the related value onto the
     * parent's column exactly as a lazy read would have materialised it, and rendering stays
     * gated on the transformer's `isIncludedRelationship` (which the eager set never touches),
     * so eager-loading changes only the query plan, never the document.
     *
     * The relations resolve against the HIDDEN-INCLUSIVE declared set via
     * {@see TypeMetadataResolver::relationNamedIncludingHidden()} (NOT
     * {@see TypeMetadataResolver::relationsFor()}, which filters hidden out): an `on()`
     * attribute's backing relation is idiomatically `hidden()`, so it must be found there.
     * A standalone/bare serializer with no field inventory does not implement
     * {@see DeclaresEagerLoadsInterface}, so it declares no eager loads (skipped).
     *
     * Every segment is a DECLARED to-one relation — guaranteed by core's
     * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator} (bundle's {@see EagerLoadWarmer}),
     * which throws a developer-facing `\LogicException` at boot / container warm-up
     * (`cache:clear` / deploy) on an unknown segment OR a to-many segment at any depth. So this
     * pass assumes a validated, all-to-one declaration: there is no windowed-to-many interaction
     * and no rendering contradiction to guard against. A polymorphic / inventory-less segment
     * whose next type cannot be resolved to a single registered type is left LAZY (the walk stops
     * on that branch), exactly as the include walk leaves it unbatched.
     *
     * @param list<object>         $entities
     * @param array<string, true> &$loaded the level-1 "already loaded" guard, keyed by relation name (shared with the include walk)
     */
    private function eagerLoad(
        Server $server,
        SerializerInterface $serializer,
        array $entities,
        string $type,
        JsonApiRequestInterface $request,
        array &$loaded,
    ): void {
        if (!$serializer instanceof DeclaresEagerLoadsInterface) {
            return;
        }

        // Group the declared (possibly dotted) eager paths into a prefix tree so a shared
        // ancestor — e.g. `author` for both `author.country` and `author.city` — is walked
        // (and loaded) ONCE, its loaded targets seeding every child branch.
        $tree = $this->eagerTree($serializer->eagerLoadRelationshipPaths());
        if ($tree === []) {
            return;
        }

        // The level-1 guard is the include walk's $loaded, so a top-level eager relation that
        // is also `?include`'d loads once. Deeper levels get a fresh per-level guard inside
        // the recursion (a different entity set / type).
        $this->eagerLoadLevel($server, $entities, $type, $request, $tree, $loaded);
    }

    /**
     * Walks ONE eager-load level: for each child relation in `$tree` (the prefix sub-tree
     * rooted at the current type), batch-loads it across `$entities` (unless the level guard
     * `$loaded` already has it — then it reads the already-loaded targets straight off the
     * parents, so an overlap with `?include` or a sibling eager path loads once), then
     * recurses into the loaded targets for the child's own sub-tree, resolving the next type
     * via {@see RelationInterface::relatedTypes()}.
     *
     * Every segment is a DECLARED to-one relation (guaranteed by core's warm-up
     * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator}), so there is no windowed-to-many
     * interaction and no rendering contradiction: eager-loading a to-one never flips its
     * linkage. A relation that cannot be resolved on the current type, or whose next type is
     * polymorphic / unregistered, stops that branch (lazy) — never throws here.
     *
     * @param list<object>         $entities
     * @param array<string, mixed> $tree    the prefix sub-tree at this level: relation name => its own (nested) sub-tree
     * @param array<string, true> &$loaded  the per-level "already loaded" guard, keyed by relation name
     */
    private function eagerLoadLevel(
        Server $server,
        array $entities,
        string $type,
        JsonApiRequestInterface $request,
        array $tree,
        array &$loaded,
    ): void {
        if ($entities === []) {
            return;
        }

        foreach ($tree as $relationName => $children) {
            $relation = $this->types->relationNamedIncludingHidden($server, $type, $relationName);
            if ($relation === null) {
                // Unknown segment: core's warm-up validation throws for this, so a warmed
                // app never reaches here. Leave the branch lazy as a runtime safety net.
                continue;
            }

            // Every `on()` segment is a to-one (core's warm-up validation throws on a to-many
            // segment at any depth), so there is no windowed-to-many interaction and no
            // rendering contradiction to guard against — eager-loading a to-one never flips
            // its linkage. The walk proceeds straight to the load.

            if (isset($loaded[$relationName])) {
                // Already loaded at this level (an overlap with `?include` or a sibling eager
                // path): read the loaded targets off the parents rather than re-batch.
                $targets = $this->collectLoadedTargets($entities, $relation);
            } else {
                $loadedTargets = $this->executeLoad($server, $entities, $type, $relation, $request);
                $loaded[$relationName] = true;
                if ($loadedTargets === null) {
                    // The relation could not be batched (polymorphic / no batching provider):
                    // it renders lazily and has no single next type to descend into.
                    continue;
                }
                $targets = $loadedTargets;
            }

            if (!\is_array($children) || $children === [] || $targets === []) {
                continue;
            }

            // Advance to the next level. A relation with anything other than a single related
            // type (polymorphic / inventory-less) cannot be walked to one next type, so its
            // sub-tree is left lazy — exactly as the include walk leaves it (NOT thrown; core's
            // warm-up validation leaves such a branch unvalidated too).
            $relatedTypes = $relation->relatedTypes();
            if (\count($relatedTypes) !== 1) {
                continue;
            }

            $nextType = $relatedTypes[0];
            if (!$server->hasSerializerFor($nextType)) {
                continue;
            }

            // A fresh guard for the deeper level: it is a different entity set / type, and the
            // child sub-tree already deduped sibling branches sharing this prefix.
            $nextLoaded = [];
            /** @var array<string, mixed> $children */
            $this->eagerLoadLevel($server, $targets, $nextType, $request, $children, $nextLoaded);
        }
    }

    /**
     * Folds a flat list of (possibly dotted) eager paths into a prefix tree: a nested map
     * `relation name => sub-tree`, where a leaf relation maps to an empty array. A shared
     * prefix collapses to one node, so `['author.country', 'author.city', 'publisher']`
     * becomes `['author' => ['country' => [], 'city' => []], 'publisher' => []]` — the walk
     * then loads `author` once and seeds both children from its targets.
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
     * Batch-loads a single included relation across `$entities` (through {@see executeLoad()}),
     * unless the eager pass already loaded it, then recurses into the loaded targets for
     * nested includes. A polymorphic relation, or a related type with no batching provider,
     * is left to a lazy load (the orchestrator simply does not batch it).
     *
     * @param list<object>         $entities
     * @param ?int                 $maxDepth     the effective include-depth cap (null = unlimited)
     * @param list<string>|null    $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     * @param array<string, true> &$loaded       the per-level "already loaded" guard (an eager-loaded relation is not re-loaded)
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
        array &$loaded,
    ): void {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            return;
        }

        $relatedType = $relatedTypes[0];

        // An overlap with the eager pass: the relation is already loaded onto the column, so
        // skip the (redundant) batch and read the loaded targets straight off the parents to
        // seed the next include level — the load runs ONCE.
        if (isset($loaded[$relation->name()])) {
            $targets = $this->collectLoadedTargets($entities, $relation);
        } else {
            $targets = $this->executeLoad($server, $entities, $type, $relation, $request);
            if ($targets === null) {
                return;
            }
            $loaded[$relation->name()] = true;
        }

        // Thread the root-scoped safeguards into the next level unchanged: the cap and
        // whitelist are a property of the root resource, not of each hop.
        $this->preload($targets, $relatedType, $request, $childPath, $maxDepth, $allowedPaths, rootResolved: true);
    }

    /**
     * Batch-loads `$relation` across `$entities` through the PRIMARY type's provider in
     * PLAIN-INCLUDE (fast-path) mode — an empty filter/sort criteria with a NULL window loads
     * the WHOLE related set per parent (`WHERE fk IN`, no slice) — and writes each parent's
     * result back onto its relation column via {@see Accessor::set}, returning the flat list
     * of loaded targets (to seed a nested include level). Returns `null` when the relation
     * cannot be batched — a polymorphic relation (more than one related type, skipped HERE so
     * even the in-memory provider stays lazy and includes stay byte-identical with the
     * Doctrine boundary) or a related type with no batching provider — so the column is left
     * untouched and the relation renders lazily.
     *
     * @param list<object> $entities
     *
     * @return list<object>|null the loaded targets, or `null` when the relation cannot be batched
     */
    private function executeLoad(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?array {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            return null;
        }

        if (!$this->providers->supportsType($relatedTypes[0])) {
            return null;
        }

        $column = $relation->column() ?? $relation->name();

        // Snapshot each parent's column value before the batch reads it, so a to-many
        // write-back can restore the column's container type (a Doctrine Collection
        // property cannot take a raw array). Keyed by object id.
        $snapshots = [];
        foreach ($entities as $entity) {
            $snapshots[\spl_object_id($entity)] = Accessor::get($entity, $column);
        }

        $batch = $this->providers->forType($type)
            ->fetchRelatedCollectionBatch($type, $entities, $relation, $this->plainIncludeCriteria($request), $request);

        $serializer = $server->serializerFor($type);

        $targets = [];
        foreach ($entities as $entity) {
            $result = $batch->for($serializer->getId($entity));
            $items = $this->itemsOf($result);
            $this->writeBack($entity, $relation, $column, $items, $snapshots[\spl_object_id($entity)] ?? null);
            foreach ($items as $target) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Reads the already-loaded targets of `$relation` straight off `$entities` (for an
     * include that overlaps an eager load already written onto the column) so the nested
     * include level is seeded without re-running the batch.
     *
     * @param list<object> $entities
     *
     * @return list<object>
     */
    private function collectLoadedTargets(array $entities, RelationInterface $relation): array
    {
        $column = $relation->column() ?? $relation->name();

        $targets = [];
        foreach ($entities as $entity) {
            $value = Accessor::get($entity, $column);
            if ($value === null) {
                continue;
            }

            if ($relation->isToMany()) {
                $members = $value instanceof \Doctrine\Common\Collections\Collection
                    ? $value->toArray()
                    : $value;
                if (\is_iterable($members)) {
                    foreach ($members as $member) {
                        if (\is_object($member)) {
                            $targets[] = $member;
                        }
                    }
                }

                continue;
            }

            if (\is_object($value)) {
                $targets[] = $value;
            }
        }

        return $targets;
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
