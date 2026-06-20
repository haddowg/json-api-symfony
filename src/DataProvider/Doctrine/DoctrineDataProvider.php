<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CriteriaApplier;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\Keyset\CursorTokenMinter;
use haddowg\JsonApiBundle\DataProvider\Keyset\KeysetColumn;
use haddowg\JsonApiBundle\DataProvider\Keyset\KeysetResolver;
use haddowg\JsonApiBundle\DataProvider\PivotAwareProviderInterface;
use haddowg\JsonApiBundle\DataProvider\PivotCollectionResult;
use haddowg\JsonApiBundle\DataProvider\PivotFields;
use haddowg\JsonApiBundle\DataProvider\RelatedBatch;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;

/**
 * The reference Doctrine ORM read provider, wired only when `doctrine/orm` is
 * installed **and** at least one resource maps an entity (the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * removes the service otherwise), because Doctrine is a `require-dev` +
 * `suggest` dependency, not a hard one.
 *
 * A collection fetch is one `QueryBuilder` pipeline: every supporting
 * {@see DoctrineExtensionInterface} customizes the builder first (base
 * constraints, query shaping), then the shared {@see CriteriaApplier} matches
 * the requested `filter[…]`/`sort` parameters against the declared
 * vocabularies and pushes each down through the
 * {@see DoctrineFilterHandler}/{@see DoctrineSortHandler}; a windowed fetch
 * then runs a `COUNT` over the filtered (un-ordered, un-windowed) query before
 * applying the window as `LIMIT`/`OFFSET` — items are never over-fetched.
 * Single fetches run through the same extension pipeline (so a scope holds for
 * `GET /{type}/{id}` too), falling back to `find()` — and its identity-map
 * fast path — only when no extension supports the type.
 *
 * The `type → entity-class` map is populated by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * from each resource's `#[AsJsonApiResource(entity: …)]` declaration.
 *
 * One instance serves every entity-mapped type — a different entity class per
 * type — so `TEntity` cannot narrow past `object`.
 *
 * When a type's resource attaches an id encoder ({@see \haddowg\JsonApi\Resource\Field\Id::encodeUsing()})
 * the JSON:API `id` is the wire form of a distinct storage key, so this provider
 * **decodes** the route `{id}` to its storage key (via the injected
 * {@see IdEncoderResolver}) before every lookup — the wire-id SPI never changes,
 * only the Doctrine impl decodes (ADR 0038). An undecodable id short-circuits to a
 * `404` (no query runs). A type with no encoder decodes to itself, so the path is
 * identical to today.
 *
 * Includes are batch eager-loaded by the provider-agnostic
 * {@see \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher} (bundle ADR 0062),
 * which drives this provider's {@see fetchRelatedCollectionBatch()} in plain-include
 * (fast-path) mode one query per level — the successor to the dissolved
 * `PreloadsIncludesInterface` + `shipmonk/doctrine-entity-preloader`. A relation this
 * provider cannot batch (a computed/`extractUsing` column that is not a real
 * association, or a composite-id target) returns an empty batch, so the orchestrator's
 * write-back is a no-op and the relation renders lazily — the document is identical.
 *
 * @implements DataProviderInterface<object>
 * @implements PivotAwareProviderInterface<object>
 */
final class DoctrineDataProvider implements DataProviderInterface, PivotAwareProviderInterface
{
    /**
     * The root alias every generated QueryBuilder uses; handlers re-read it
     * from the builder, so this is a naming choice, not a contract.
     */
    private const string ROOT_ALIAS = 'resource';

    private readonly CriteriaApplier $applier;

    private readonly WindowExecutor $windowExecutor;

    private readonly DoctrineFilterHandler $filterHandler;

    private readonly DoctrineSortHandler $sortHandler;

    private readonly RelationScope $relationScope;

    private readonly KeysetResolver $keysetResolver;

    private readonly CursorTokenMinter $minter;

    /**
     * @var list<DoctrineExtensionInterface>
     */
    private readonly array $extensions;

    private readonly WindowedRelationBatch $windowedBatch;

    /**
     * @param array<string, class-string>            $entityClassByType a `type → entity FQCN` map
     * @param IdEncoderResolver                      $idEncoders        resolves a type's id encoder (route `{id}` decode)
     * @param iterable<DoctrineExtensionInterface>   $extensions        in descending tag-priority order
     * @param ?PivotAssociationResolver              $pivotAssociations resolves a `belongsToMany` pivot relation's association entity (always wired under Doctrine)
     * @param bool                                   $windowFunctions   whether the windowed-include batch runs the bounded ROW_NUMBER/COUNT OVER native query (`json_api.doctrine.window_functions`, default true) or the per-parent bounded fallback (bundle ADR 0065)
     * @param iterable<DoctrineFilterArmInterface>   $filterArms        author arms for custom `FilterInterface` types (autoconfigured tag)
     * @param iterable<DoctrineSortArmInterface>     $sortArms          author arms for custom `SortInterface` types (autoconfigured tag)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
        private readonly IdEncoderResolver $idEncoders,
        iterable $extensions = [],
        private readonly ?PivotAssociationResolver $pivotAssociations = null,
        private readonly bool $windowFunctions = true,
        iterable $filterArms = [],
        iterable $sortArms = [],
    ) {
        $this->extensions = \is_array($extensions) ? \array_values($extensions) : \iterator_to_array($extensions, false);
        $this->applier = new CriteriaApplier();
        $this->windowExecutor = new WindowExecutor();
        $this->filterHandler = new DoctrineFilterHandler($filterArms);
        $this->sortHandler = new DoctrineSortHandler($sortArms);
        $this->relationScope = new RelationScope($this->entityManager);
        $this->keysetResolver = new KeysetResolver();
        $this->minter = new CursorTokenMinter(new CursorCodec());
        $this->windowedBatch = new WindowedRelationBatch(
            $this->entityManager,
            $this->idEncoders,
            $this->applier,
            $this->filterHandler,
            $this->sortHandler,
        );
    }

    public function supports(string $type): bool
    {
        return isset($this->entityClassByType[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $entityClass = $this->entityClassFor($type);

        // Decode the wire id to its storage key when the type declares an encoder.
        // An undecodable id is a 404: no entity holds that key, so short-circuit
        // without querying (the SPI stays wire-id — only this impl decodes; ADR 0038).
        $encoder = $this->idEncoders->encoderFor($type);
        if ($encoder !== null) {
            $storageKey = $encoder->decode($id);
            if ($storageKey === null) {
                return null;
            }
        } else {
            $storageKey = $id;
        }

        $extensions = $this->extensionsFor($type);
        if ($extensions === []) {
            return $this->entityManager->find($entityClass, $storageKey);
        }

        $builder = $this->entityManager
            ->getRepository($entityClass)
            ->createQueryBuilder(self::ROOT_ALIAS);

        // String-id lookups imply a single-field identifier (composite ids
        // cannot round-trip through one JSON:API id segment anyway).
        $idField = $this->entityManager->getClassMetadata($entityClass)->getSingleIdentifierFieldName();
        $builder
            ->andWhere(\sprintf('%s.%s = :jsonapi_id', self::ROOT_ALIAS, $idField))
            ->setParameter('jsonapi_id', $storageKey);

        foreach ($extensions as $extension) {
            // The SPI fetchOne carries no request, so the context's request is null.
            $builder = $extension->apply($builder, new ExtensionContext($type, QueryPurpose::FetchOne));
        }

        $result = $builder->getQuery()->getOneOrNullResult();

        return \is_object($result) ? $result : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $entityClass = $this->entityClassFor($type);

        $builder = $this->entityManager
            ->getRepository($entityClass)
            ->createQueryBuilder(self::ROOT_ALIAS);

        foreach ($this->extensionsFor($type) as $extension) {
            // The primary collection: the SPI fetchCollection carries no request, so the
            // context's request is null. A related load of the same type reports the
            // distinct FetchRelatedCollection purpose (and carries the request).
            $builder = $extension->apply($builder, new ExtensionContext($type, QueryPurpose::FetchCollection));
        }

        // A cursor (keyset) window is its own pushed-down execution (the keyset
        // WHERE + the forced NULL=largest ORDER BY); the OffsetWindow / null-window
        // path stays byte-identical below. The keyset still applies the FILTERS via
        // the shared applier (and validates `?sort` through the resolver) but builds
        // its OWN order, never the plain sort handler (bundle ADR 0063).
        if ($criteria->window instanceof CursorWindow) {
            return $this->runCursor($type, $entityClass, $builder, $criteria, $criteria->window);
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        // Count-free by default (G21): the executor counts the pre-window total and
        // fetches the windowed page only when the handler resolved a COUNT for this
        // fetch (the paginator's withCount() author opt-in, or ?withCount=_self_ under
        // a countable() resource); otherwise it fetches count-free via the window+1
        // probe (no COUNT) and reports `hasMore` (bundle ADR 0075).
        return $this->windowExecutor->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: fn(): array => $this->items($builder),
            count: fn(): int => $this->count($builder),
            page: fn(int $offset, int $limit): array => $this->items(
                (clone $builder)->setFirstResult($offset)->setMaxResults($limit),
            ),
            // Unused on the countable branch, but supplied for the contract.
            probe: fn(int $offset, int $limit): array => $this->items(
                (clone $builder)->setFirstResult($offset)->setMaxResults($limit),
            ),
        );
    }

    /**
     * The cursor (keyset) execution pushed down to DQL — the twin of the in-memory
     * witness ({@see \haddowg\JsonApiBundle\DataProvider\Keyset\InMemoryKeyset}),
     * which is the ground truth (bundle ADR 0063). It resolves the keyset columns
     * (the active sort + the appended/deduped PK; validates `?sort`), applies the
     * filters, checks the cursor against the resolved columns (a stale cursor →
     * 400), then via {@see DoctrineKeyset} builds the forced NULL=largest
     * `ORDER BY` and the IS-NULL-branched keyset `WHERE`, over-fetching `limit + 1`
     * through the shared {@see WindowExecutor::runCursor()}. A backward
     * (`page[before]`) page flips every direction (which flips the null bucket via
     * the leading `CASE WHEN c IS NULL` term) and the after-predicate, then reverses
     * the sliced rows to natural forward order before minting.
     *
     * @param class-string $entityClass
     *
     * @return CursorCollectionResult<object>
     */
    private function runCursor(
        string $type,
        string $entityClass,
        QueryBuilder $builder,
        CollectionCriteria $criteria,
        CursorWindow $window,
    ): CursorCollectionResult {
        $metadata = $this->entityManager->getClassMetadata($entityClass);
        $pkColumn = $metadata->getSingleIdentifierFieldName();

        $columns = $this->keysetResolver->resolve($criteria, $pkColumn);

        // Apply the FILTERS only (the keyset owns the order). A sort-stripped,
        // window-less criteria reuses the shared applier so the filter semantics
        // are identical to a plain fetch, and the empty sort adds no ORDER BY.
        $builder = $this->applier->apply(
            $this->filtersOnly($criteria),
            $builder,
            $this->filterHandler,
            $this->sortHandler,
        );

        // page[before] wins over page[after]: a backward page flips the directions
        // (incl. the null bucket) and the after-predicate, so "after under the
        // reversed order" is "before under the natural order".
        $backward = $window->before !== null;
        $boundary = $backward ? $window->before : $window->after;
        $orderColumns = $backward
            ? \array_map(static fn(KeysetColumn $c): KeysetColumn => new KeysetColumn($c->column, !$c->descending), $columns)
            : $columns;

        if ($boundary !== null) {
            $parameter = $backward ? 'page[before]' : 'page[after]';
            $this->keysetResolver->assertFresh($boundary, $columns, $parameter);
        }

        $keyset = new DoctrineKeyset($metadata, self::ROOT_ALIAS);
        if ($boundary !== null) {
            $keyset->applyAfter($builder, $boundary, $orderColumns);
        }
        $keyset->orderBy($builder, $orderColumns);

        return $this->windowExecutor->runCursor(
            $window,
            // Over-fetch limit+1 in the (possibly flipped) order. For a backward
            // page the surplus is dropped by runCursor BEFORE this closure mints,
            // so reverse here only after the slice — done in the cursors closure.
            probe: fn(CursorWindow $w): array => $this->items(
                (clone $builder)->setMaxResults($w->limit + 1),
            ),
            cursors: function (array $rows, bool $hasMore) use ($window, $columns, $backward, $metadata): CursorCollectionResult {
                // Re-orient a backward page to natural forward order for rendering.
                $page = $backward ? \array_reverse($rows) : $rows;

                return $this->minter->mint(
                    $window,
                    $columns,
                    \array_values($page),
                    $hasMore,
                    static fn(object $row, string $column): string|int|float|bool|null => CursorTokenMinter::coerce(
                        $metadata->getFieldValue($row, $column),
                    ),
                );
            },
        );
    }

    /**
     * A sort-stripped, window-less copy of `$criteria` so the shared applier
     * applies only its FILTERS on the cursor path (the keyset owns the order).
     */
    private function filtersOnly(CollectionCriteria $criteria): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters(
                $criteria->queryParameters->fields,
                $criteria->queryParameters->includes,
                sort: [],
                filter: $criteria->queryParameters->filter,
                pagination: $criteria->queryParameters->pagination,
            ),
            $criteria->filters,
            sorts: [],
            window: null,
            defaultSort: [],
            aliasOf: $criteria->aliasOf,
        );
    }

    /**
     * Scopes a related to-many to its parent. A single-valued inverse
     * association (the OneToMany case, whose related entity carries the owning
     * foreign key) is scoped by that FK as a fast-path; any other to-many
     * (owning-side, or many-to-many on either side) is scoped by an `IN`
     * subquery rooted on the parent — the related entity stays the OUTER query
     * root, so the shared filter/sort/count/window machinery applies
     * identically to both branches. `$request` is unused (Doctrine push-down).
     *
     * A polymorphic to-many ({@see \haddowg\JsonApi\Resource\Field\MorphToMany},
     * `relatedTypes()` of more than one type) is a deliberate boundary — like the
     * many-to-many subquery scope above, this provider executes one scoped query
     * against a single related entity class, and a polymorphic collection's members
     * span entity classes, so there is no single query to run. It throws; a host
     * that needs it supplies a custom {@see DataProviderInterface} that resolves the
     * related members across types (ADR 0032).
     */
    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        if (\count($relation->relatedTypes()) > 1) {
            throw new \LogicException(\sprintf(
                'The %s does not support a polymorphic (morph-to-many) related collection for relationship "%s": its members span entity classes and cannot be one scoped query. Supply a custom DataProvider that resolves the related members across types.',
                self::class,
                $relation->name(),
            ));
        }

        $relatedClass = $this->entityClassFor($relatedType);

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS);

        // Scope the related-rooted query to the parent — a single-valued inverse
        // FK fast-path, otherwise an IN subquery rooted on the parent (the related
        // entity stays the outer root, so the shared machinery below is identical).
        $this->relationScope->scopeToParent($builder, self::ROOT_ALIAS, $relatedClass, $parent, $relation);

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, new ExtensionContext($relatedType, QueryPurpose::FetchRelatedCollection, $request));
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        // The window/count/count-free tail runs through the shared executor (core
        // ADR 0061). Count-free by default (G21): the related endpoint counts only
        // when the handler resolved a COUNT for this fetch (`$criteria->wantsCount` —
        // the relation paginator's withCount() author opt-in, or ?withCount=_self_
        // under a countable() relation; the handler 400s an un-countable `_self_`
        // first). Otherwise the count-free branch runs (no COUNT; the probe
        // over-fetches by one and the surplus drives the count-free page's `next`
        // link). The executor passes `limit + 1` to the probe, so the closure does
        // NOT add one itself.
        return $this->windowExecutor->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: fn(): array => $this->items($builder),
            count: fn(): int => $this->count($builder),
            page: fn(int $offset, int $limit): array => $this->items(
                (clone $builder)->setFirstResult($offset)->setMaxResults($limit),
            ),
            probe: fn(int $offset, int $limit): array => $this->items(
                (clone $builder)->setFirstResult($offset)->setMaxResults($limit),
            ),
        );
    }

    /**
     * The batched related-collection fetch for a page of parents (Approach B, bundle
     * ADR 0061): ONE query scopes the related entity to the WHOLE page and projects
     * the parent discriminator, the shared {@see CriteriaApplier} applies the
     * filters/sorts IN that query, the flat result is materialized, partitioned by
     * parent in PHP, and each partition windowed through the shared
     * {@see WindowExecutor} — so a windowed collection include costs O(N) statements
     * (one per relation), not O(M*N) (the per-parent
     * {@see fetchRelatedCollection()} loop the {@see \haddowg\JsonApiBundle\DataProvider\RelationshipWindowBatcher}
     * drove). Because the whole filtered set is materialized, the per-partition window
     * (the countable count / the count-free `hasMore` probe) is computed in PHP with no
     * further query.
     *
     * The scope has two shapes ({@see RelationScope::scopeBatchToParents()}): an
     * inverse-FK fast-path roots on the related entity and scopes by the owning FK
     * `IN` the page; an owning-side / many-to-many shape roots on the PARENT and joins
     * the related collection (mirroring {@see countRelated()}). Either way the related
     * entity is reachable under the scope's related alias, so the applier applies the
     * related vocabulary on it (passed as the default alias), and the parent
     * discriminator is selected so the rows partition by parent.
     *
     * A polymorphic to-many keeps the same boundary as {@see fetchRelatedCollection()}:
     * its members span entity classes, so there is no single scoped query — it throws
     * (supply a custom provider).
     *
     * @param list<object> $parents
     */
    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        if (\count($relation->relatedTypes()) > 1) {
            throw new \LogicException(\sprintf(
                'The %s does not support a polymorphic (morph-to-many) related collection for relationship "%s": its members span entity classes and cannot be one scoped query. Supply a custom DataProvider that resolves the related members across types.',
                self::class,
                $relation->name(),
            ));
        }

        if ($parents === []) {
            return new RelatedBatch([]);
        }

        // The opt-out moves into how the provider batches (bundle ADR 0062): a column
        // that is not a real Doctrine association (a computed/extractUsing value, or an
        // alias that is not the association name), or a target with a composite id, is
        // not batchable here — return an EMPTY batch so the orchestrator's write-back is
        // a no-op and the relation renders lazily, exactly as the retired preloader
        // skipped it. (These were the preloader's hasAssociation/composite-id guards.)
        if (!$this->isBatchableAssociation($parents[0], $relation)) {
            return new RelatedBatch([]);
        }

        $relatedType = $relation->relatedTypes()[0] ?? $parentType;
        $relatedClass = $this->entityClassFor($relatedType);

        // A to-one include is the WHERE id IN (:targetIds) arm (bundle ADR 0062): the
        // page's target ids are projected as scalars off the already-loaded parents (no
        // proxy init), loaded in ONE id-IN query, and partitioned 1:1 to their parents.
        if (!$relation->isToMany()) {
            return $this->fetchToOneBatch($parentType, $parents, $relation, $relatedClass, $request);
        }

        // A WINDOWED to-many include (an OffsetWindow, the profile's page-1 slice) is
        // BOUNDED rather than materialised whole (bundle ADRs 0065/0066). With window
        // functions on (the default) and no query extension on the related type, ONE
        // native ROW_NUMBER/COUNT OVER query fetches only ~limit rows per parent AND the
        // REAL per-parent total — for a FILTERED include too: the inner scoped+filtered
        // query is built by the same DQL filter executor the endpoint runs, then wrapped
        // for the window (bundle ADR 0066), so the per-parent fallback no longer serves a
        // filtered include on the `on` path. Only an extended related type (or window
        // functions off) routes to the per-parent BOUNDED fallback: a loop over the proven
        // single-parent fetch, each a real LIMIT push-down. A PLAIN include (no window)
        // keeps the materialise-and-partition fast path below, UNTOUCHED.
        if ($criteria->window instanceof OffsetWindow) {
            if ($this->windowFunctions && $this->isNativelyWindowable($relatedType, $relation, $criteria)) {
                return $this->windowedBatch->fetch($parentType, $parents, $relatedType, $relatedClass, $relation, $criteria);
            }

            return $this->fetchWindowedBatchPerParent($parentType, $parents, $relatedType, $relation, $criteria, $request);
        }

        $scope = $this->relationScope->scopeBatchToParents($relatedClass, $parents, $relation);
        $builder = $scope->builder;

        // Apply the related type's query extensions ON the related entity, never the
        // query root. For the inverse-FK shape the root IS the related entity, so the
        // extensions apply to the builder as the unbatched path does. For the pair shape
        // the query roots on the PARENT (the related entity is a join alias), so an
        // extension reading $builder->getRootAliases()[0] would scope the parent — the
        // wrong entity; instead the extension constraint is built on a related-rooted
        // subquery and the pair query's related membership is filtered to it, so a
        // soft-delete / tenant / published-only related extension excludes the right
        // members exactly as the related-rooted unbatched fetch does (bundle ADR 0061).
        $builder = $this->applyRelatedBatchExtensions($builder, $scope, $relatedType, $request);

        // Apply the related vocabulary on the scope's related alias (the query root for
        // the inverse-FK shape, the join alias for the parent-rooted shape), so the
        // filters/sorts land on the related entity exactly as the per-parent fetch does.
        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler, $scope->relatedAlias);

        // Materialize the scoped rows once, then partition by parent — Approach B's
        // single round-trip.
        $partitions = $this->partitionByParent($builder, $scope, $parentType);

        // Window each parent's partition through the shared executor. The whole set is
        // already in PHP, so the closures slice/count the partition with no query —
        // a counted batch (the include path's `wantsCount: $relation->isCountable()`,
        // set by the RelationshipWindowBatcher) counts the partition size, a count-free
        // one probes it limit+1, identical to fetchRelatedCollection()'s tail.
        $results = [];
        foreach ($partitions as $wireId => $members) {
            $results[$wireId] = $this->windowExecutor->run(
                $criteria->window,
                countable: $criteria->wantsCount,
                all: static fn(): array => $members,
                count: static fn(): int => \count($members),
                page: static fn(int $offset, int $limit): array => \array_slice($members, $offset, $limit),
                probe: static fn(int $offset, int $limit): array => \array_slice($members, $offset, $limit),
            );
        }

        return new RelatedBatch($results);
    }

    /**
     * Whether a windowed to-many include can run the native ROW_NUMBER batch
     * ({@see WindowedRelationBatch}) rather than the per-parent bounded fallback (bundle
     * ADRs 0065/0066). The native path now serves a FILTERED and an unfiltered windowed
     * include alike — the inner scoped query is built by the EXACT same DQL machinery the
     * related endpoint runs (RelationScope-style parent-scope + the shared
     * {@see CriteriaApplier} driving the #1 DQL {@see DoctrineFilterHandler}), then wrapped
     * with the window functions over the generated SQL aliases READ off its
     * {@see \Doctrine\ORM\Query\ResultSetMapping}; so a filtered windowed include is
     * witness-equivalent for free (it IS the same executor) and runs in ONE bounded query
     * (bundle ADR 0066). The filter check is therefore RETIRED from this gate.
     *
     * It still requires **no query extension** on the related type — a soft-delete /
     * tenant / published-only extension adds a DQL `WHERE` keyed on the query root that the
     * batched native shape does not thread, so an extended related type routes to the
     * per-parent fallback (which applies the extension through the proven DQL path; folding
     * extensions onto the native path is out of scope of this slice).
     *
     * The relation's sort and filters are handled natively; the native builder itself
     * rejects a shape it cannot express (a polymorphic to-many, a composite-id
     * parent/related, a computed sort) by throwing, so this gate only screens the
     * routing-level condition.
     */
    private function isNativelyWindowable(string $relatedType, RelationInterface $relation, CollectionCriteria $criteria): bool
    {
        // A query extension on the related type still routes to the per-parent fallback —
        // its DQL WHERE is applied on the related root, which the batched native shape does
        // not thread (the only routing condition; the filter deviation is removed).
        return $this->extensionsFor($relatedType) === [];
    }

    /**
     * The OFF / EXTENDED fallback for a windowed to-many include (bundle ADRs 0065/0066):
     * a per-parent BOUNDED loop over the proven single-parent
     * {@see fetchRelatedCollection()}, assembled into a {@see RelatedBatch} keyed by
     * parent wire id. Each call pushes down a REAL `LIMIT`/`OFFSET` (a countable relation
     * counts the pre-window total, a non-countable one probes limit+1 count-free) and
     * applies the criteria filters/sorts AND the related type's query extensions through
     * the same DQL path the related-collection endpoint runs — so it is witness-correct
     * by construction (it IS the per-parent twin the in-memory witness mirrors) and
     * BOUNDED (M queries, each a LIMIT, never the whole-set materialise the 6a batch ran).
     * A FILTERED include no longer routes here on the `on` path — the native batch now
     * serves it in ONE bounded query (bundle ADR 0066); this fallback serves only window
     * functions OFF and an EXTENDED related type.
     *
     * @param list<object> $parents
     */
    private function fetchWindowedBatchPerParent(
        string $parentType,
        array $parents,
        string $relatedType,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        $parentMetadata = $this->entityManager->getClassMetadata($parents[0]::class);
        $encoder = $this->idEncoders->encoderFor($parentType);

        $results = [];
        foreach ($parents as $parent) {
            $wireId = $this->parentWireId($parent, $parentMetadata, $encoder);
            if ($wireId === null) {
                continue;
            }

            $results[$wireId] = $this->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
        }

        return new RelatedBatch($results);
    }

    /**
     * The to-one include arm of {@see fetchRelatedCollectionBatch()} (bundle ADR 0062):
     * the successor to ShipMonk's to-one preload (one `WHERE id IN (:targetIds)` over the
     * page's distinct target ids — ONE query for the level, matching ShipMonk's budget).
     * It reads each parent's target id directly off the already-managed parent (a Doctrine
     * proxy exposes its identifier WITHOUT initialising it, so no extra round-trip), loads
     * the distinct targets in ONE id-IN query keyed by id, and partitions each parent to a
     * 0-or-1 {@see CollectionResult} keyed by the parent's wire id — so the orchestrator
     * writes back `items[0] ?? null` onto the to-one column.
     *
     * Reading the target off the managed parent (rather than a scalar `IDENTITY()`
     * projection query) keeps the to-one level at ONE store round-trip — the id-IN load —
     * matching the retired ShipMonk preload's budget and so the nested include bound.
     *
     * A to-one include carries no filter/sort/window (the fast path), so no criteria is
     * applied; the related type's query extensions still apply to the id-IN load so a
     * soft-delete / tenant / published-only target is excluded exactly as a lazy load
     * would be (a parent whose target the extension hides then renders `data: null`, the
     * same lazy result).
     *
     * @param list<object> $parents
     * @param class-string $relatedClass
     */
    private function fetchToOneBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        string $relatedClass,
        ?JsonApiRequestInterface $request,
    ): RelatedBatch {
        $property = $relation->column() ?? $relation->name();
        $parentMetadata = $this->entityManager->getClassMetadata($parents[0]::class);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);
        $relatedIdField = $relatedMetadata->getSingleIdentifierFieldName();
        $encoder = $this->idEncoders->encoderFor($parentType);

        // Read each parent's target reference + its id off the managed parent. A Doctrine
        // proxy carries its identifier, so reading the FK id never initialises the target
        // (no extra round-trip) — the ShipMonk "read the reference id" shape. A parent
        // whose to-one is null carries no target.
        $parentTargetId = [];
        $targetIds = [];
        foreach ($parents as $parent) {
            $wireId = $this->parentWireId($parent, $parentMetadata, $encoder);
            if ($wireId === null) {
                continue;
            }

            $targetId = $this->toOneTargetId($parent, $property, $relatedMetadata, $relatedIdField);
            $parentTargetId[$wireId] = $targetId;
            if ($targetId !== null) {
                $targetIds[(string) $targetId] = $targetId;
            }
        }

        $targetsById = $this->loadToOneTargets($relatedClass, $relatedMetadata, $relatedIdField, $relation->relatedTypes()[0] ?? $parentType, \array_values($targetIds), $request);

        // Partition each parent to its single target (0-or-1 members), keyed by wire id.
        $results = [];
        foreach ($parentTargetId as $wireId => $targetId) {
            $target = $targetId !== null ? ($targetsById[(string) $targetId] ?? null) : null;
            $results[$wireId] = new CollectionResult($target !== null ? [$target] : []);
        }

        return new RelatedBatch($results);
    }

    /**
     * The wire id of a managed `$parent` (encoded when the parent type declares an
     * encoder), or null when its identifier is not a single scalar.
     *
     * @param \Doctrine\ORM\Mapping\ClassMetadata<object> $parentMetadata
     */
    private function parentWireId(object $parent, \Doctrine\ORM\Mapping\ClassMetadata $parentMetadata, ?IdEncoderInterface $encoder): ?string
    {
        $parentIdField = $parentMetadata->getSingleIdentifierFieldName();
        $key = $parentMetadata->getIdentifierValues($parent)[$parentIdField] ?? null;
        if (!\is_scalar($key)) {
            return null;
        }

        return $encoder !== null ? $encoder->encode($key) : (string) $key;
    }

    /**
     * The single-id of a to-one target read off the managed `$parent` WITHOUT initialising
     * it (a Doctrine proxy exposes its identifier), or null when the to-one is unset. Reads
     * the association value via the parent metadata's reflection, then the target's id via
     * the related metadata — a proxy returns its id field without a database round-trip.
     *
     * @param \Doctrine\ORM\Mapping\ClassMetadata<object> $relatedMetadata
     */
    private function toOneTargetId(object $parent, string $property, \Doctrine\ORM\Mapping\ClassMetadata $relatedMetadata, string $relatedIdField): int|string|null
    {
        $reflection = $this->entityManager->getClassMetadata($parent::class)->getReflectionProperty($property);
        if ($reflection === null || !$reflection->isInitialized($parent)) {
            return null;
        }

        $target = $reflection->getValue($parent);
        if (!\is_object($target)) {
            return null;
        }

        $key = $relatedMetadata->getIdentifierValues($target)[$relatedIdField] ?? null;
        if (\is_int($key) || \is_string($key)) {
            return $key;
        }

        return null;
    }

    /**
     * Loads the to-one targets of `$relatedClass` for the distinct `$targetIds` in ONE
     * id-IN query, applying the related type's {@see DoctrineExtensionInterface}s so a
     * scoped target (soft-deleted / un-tenant / unpublished) is excluded exactly as a
     * lazy load would exclude it, keyed by storage id. Empty when there are no target ids.
     *
     * @param class-string                  $relatedClass
     * @param \Doctrine\ORM\Mapping\ClassMetadata<object> $relatedMetadata
     * @param list<mixed>                   $targetIds
     *
     * @return array<string, object> `storageId => target`
     */
    private function loadToOneTargets(
        string $relatedClass,
        \Doctrine\ORM\Mapping\ClassMetadata $relatedMetadata,
        string $relatedIdField,
        string $relatedType,
        array $targetIds,
        ?JsonApiRequestInterface $request,
    ): array {
        if ($targetIds === []) {
            return [];
        }

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS)
            ->where(\sprintf('%s.%s IN (:jsonapi_target_ids)', self::ROOT_ALIAS, $relatedIdField))
            ->setParameter('jsonapi_target_ids', $targetIds);

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, new ExtensionContext($relatedType, QueryPurpose::FetchRelatedCollection, $request));
        }

        $loaded = $builder->getQuery()->getResult();
        \assert(\is_array($loaded));

        $targetsById = [];
        foreach ($loaded as $entity) {
            if (!\is_object($entity)) {
                continue;
            }
            $key = $relatedMetadata->getIdentifierValues($entity)[$relatedIdField] ?? null;
            if (\is_scalar($key)) {
                $targetsById[(string) $key] = $entity;
            }
        }

        return $targetsById;
    }

    /**
     * Whether `$relation` is a real, single-identifier Doctrine association on the
     * `$parent`'s entity that {@see fetchRelatedCollectionBatch()} can batch — the
     * provider-side opt-out (bundle ADR 0062), replacing the retired preloader's
     * pre-call guards. Lazy (an empty batch) when the column is not an association (a
     * computed/`extractUsing` value, or an alias that is not the association name) or
     * the target carries a composite identifier (the batch id-loads by a single id).
     */
    private function isBatchableAssociation(object $parent, RelationInterface $relation): bool
    {
        $property = $relation->column() ?? $relation->name();
        $metadata = $this->entityManager->getClassMetadata($parent::class);

        if (!$metadata->hasAssociation($property)) {
            return false;
        }

        $mapping = $metadata->getAssociationMapping($property);
        $targetEntity = $mapping['targetEntity'] ?? null;
        if (!\is_string($targetEntity)) {
            return false;
        }

        return !$this->entityManager->getClassMetadata($targetEntity)->isIdentifierComposite;
    }

    /**
     * Applies the related type's {@see DoctrineExtensionInterface}s to the batched scope's
     * query, ON the related entity in BOTH shapes — the divergence the unbatched
     * {@see fetchRelatedCollection()} avoids because its query is always related-rooted.
     *
     *  - **Inverse-FK shape** ({@see BatchScope::$relatedClass} null) — the related entity
     *    IS the query root, so each extension applies to the builder directly, reading
     *    `$builder->getRootAliases()[0]` (the related root) exactly as the unbatched path
     *    does.
     *  - **Pair shape** ({@see BatchScope::$relatedClass} set) — the query roots on the
     *    PARENT, so an extension applied to the builder would scope the PARENT (its root
     *    alias), not the related members. Instead each extension applies to a fresh
     *    related-ROOTED subquery builder (whose root alias IS the related entity, so the
     *    contract holds), and the pair query's related membership is constrained `IN` the
     *    extension-narrowed related ids — so a soft-delete / tenant / published-only related
     *    extension excludes the right members, matching the related-rooted unbatched fetch
     *    (bundle ADR 0061). With no related extension the subquery is never built and the
     *    pair query is unchanged.
     */
    private function applyRelatedBatchExtensions(QueryBuilder $builder, BatchScope $scope, string $relatedType, ?JsonApiRequestInterface $request): QueryBuilder
    {
        $extensions = $this->extensionsFor($relatedType);
        if ($extensions === []) {
            return $builder;
        }

        $context = new ExtensionContext($relatedType, QueryPurpose::FetchRelatedCollection, $request);

        // Inverse-FK shape: the related entity is the query root, so the extensions apply
        // to the builder directly (their getRootAliases()[0] is the related root).
        if ($scope->relatedClass === null || $scope->relatedIdField === null) {
            foreach ($extensions as $extension) {
                $builder = $extension->apply($builder, $context);
            }

            return $builder;
        }

        // Pair shape: the query roots on the parent, so build the extension constraints on
        // a related-ROOTED subquery (its root IS the related entity) and constrain the pair
        // query's related membership to the extension-narrowed related ids.
        $subAlias = 'jsonapi_ext_related';
        $sub = $this->entityManager
            ->getRepository($scope->relatedClass)
            ->createQueryBuilder($subAlias)
            ->select(\sprintf('%s.%s', $subAlias, $scope->relatedIdField));

        foreach ($extensions as $extension) {
            $sub = $extension->apply($sub, $context);
        }

        // Fold the subquery's parameters onto the outer (executing) builder — extensions
        // bind their constraint parameters on the builder they were handed (the subquery),
        // but only the outer builder executes the merged DQL. Appending the Parameter
        // objects preserves each one's declared DBAL type.
        $merged = $builder->getParameters();
        foreach ($sub->getParameters() as $parameter) {
            $merged->add($parameter);
        }
        $builder->setParameters($merged);

        return $builder->andWhere($builder->expr()->in(
            \sprintf('%s.%s', $scope->relatedAlias, $scope->relatedIdField),
            $sub->getDQL(),
        ));
    }

    /**
     * Materializes the batched scope's result and groups the related entities by their
     * parent's WIRE id (Approach B's PHP partition), per the scope's shape
     * ({@see BatchScope}). The inverse-FK shape's rows hydrate
     * `[0 => relatedEntity, 'jsonapi_parent_id' => parentStorageId]` (one related entity
     * per row, keyed by the projected scalar). The pair shape's rows are scalar
     * `(parentId, relatedId)` pairs — the distinct related entities are loaded by id in
     * ONE further IN-query and re-associated per pair, preserving order (see
     * {@see partitionPairs()}). Either way the parent storage id maps to the wire id the
     * partition keys on (encoded when the parent type declares an id encoder) so the
     * keys match {@see countRelated()} and {@see RelatedBatch}.
     *
     * @return array<string, list<object>> `parentWireId => related entities`, request order preserved per parent
     */
    private function partitionByParent(QueryBuilder $builder, BatchScope $scope, string $parentType): array
    {
        $encoder = $this->idEncoders->encoderFor($parentType);

        if ($scope->relatedClass !== null && $scope->relatedIdField !== null) {
            return $this->partitionPairs($builder, $scope->relatedClass, $scope->relatedIdField, $encoder);
        }

        /** @var list<array<int|string, mixed>> $rows */
        $rows = $builder->getQuery()->getResult();

        $partitions = [];
        foreach ($rows as $row) {
            $related = $row[0] ?? null;
            $storageKey = $row[BatchScope::PARENT_DISCRIMINATOR_ALIAS] ?? null;
            if (!\is_object($related) || !\is_scalar($storageKey)) {
                continue;
            }

            $wireId = $encoder !== null ? $encoder->encode($storageKey) : (string) $storageKey;
            $partitions[$wireId][] = $related;
        }

        return $partitions;
    }

    /**
     * The pair shape's partition (the owning-side / many-to-many path): the scalar
     * `(parentId, relatedId)` pairs are materialized (no entity dedup, so the
     * filtered/ordered membership is exact), the DISTINCT related ids are loaded as
     * managed entities in ONE IN-query keyed by id, and each pair is re-associated to
     * its parent's partition in the query's order. Two scalar+load queries, still O(N)
     * per relation.
     *
     * @param class-string         $relatedClass
     * @param ?IdEncoderInterface  $encoder the parent type's id encoder, or null
     *
     * @return array<string, list<object>> `parentWireId => related entities`, query order preserved per parent
     */
    private function partitionPairs(QueryBuilder $builder, string $relatedClass, string $relatedIdField, ?IdEncoderInterface $encoder): array
    {
        /** @var list<array<string, mixed>> $pairs */
        $pairs = $builder->getQuery()->getResult();

        $relatedIds = [];
        foreach ($pairs as $pair) {
            $relatedId = $pair[BatchScope::RELATED_DISCRIMINATOR_ALIAS] ?? null;
            if (\is_scalar($relatedId)) {
                $relatedIds[(string) $relatedId] = $relatedId;
            }
        }

        // Load the distinct related entities by id in one IN-query, keyed by id.
        $entitiesById = [];
        if ($relatedIds !== []) {
            $loaded = $this->entityManager->getRepository($relatedClass)
                ->createQueryBuilder('related')
                ->where(\sprintf('related.%s IN (:ids)', $relatedIdField))
                ->setParameter('ids', \array_values($relatedIds))
                ->getQuery()
                ->getResult();

            \assert(\is_array($loaded));
            $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);
            foreach ($loaded as $entity) {
                if (!\is_object($entity)) {
                    continue;
                }
                $key = $relatedMetadata->getIdentifierValues($entity)[$relatedIdField] ?? null;
                if (\is_scalar($key)) {
                    $entitiesById[(string) $key] = $entity;
                }
            }
        }

        // Re-associate each ordered pair to its parent's partition.
        $partitions = [];
        foreach ($pairs as $pair) {
            $parentKey = $pair[BatchScope::PARENT_DISCRIMINATOR_ALIAS] ?? null;
            $relatedId = $pair[BatchScope::RELATED_DISCRIMINATOR_ALIAS] ?? null;
            if (!\is_scalar($parentKey) || !\is_scalar($relatedId)) {
                continue;
            }
            $entity = $entitiesById[(string) $relatedId] ?? null;
            if ($entity === null) {
                continue;
            }
            $wireId = $encoder !== null ? $encoder->encode($parentKey) : (string) $parentKey;
            $partitions[$wireId][] = $entity;
        }

        return $partitions;
    }

    /**
     * The batched, pushed-down cardinality of `$relation` for a page of parents
     * (bundle ADR 0052). ONE grouped query rooted on the PARENT entity joins the
     * related collection and counts it per parent —
     *
     *     SELECT parent.<id>, COUNT(related.<relatedId>)
     *     FROM <ParentEntity> parent
     *     LEFT JOIN parent.<property> related
     *     WHERE parent.<id> IN (:pageIds)
     *     GROUP BY parent.<id>
     *
     * — so a collection render counts every parent's relation in a single query, no
     * N+1. The `LEFT JOIN` keeps a parent with an empty relation in the result (a
     * `0` count). Rooting on the parent (not the related entity, as
     * {@see fetchRelatedCollection()} does) lets the `GROUP BY` fan the count across
     * the whole page in one statement; the membership semantics are identical (the
     * same backing association). The map is keyed by each parent's wire id (encoded
     * when the parent type declares an id encoder), so the batcher resolves a count
     * back to its parent object at render time.
     *
     * The count is over the relation's **filtered** set: `$criteria`'s filters are
     * applied on the `related` JOIN alias via the shared alias-aware
     * {@see CriteriaApplier} (the count passes `related` as the default alias, so a
     * related filter lands on the join, not the `parent` root — bundle ADR 0060).
     * Because an `andWhere` on a `LEFT JOIN` drops a parent with no matching member,
     * the grouped result OMITS such a parent; so the page is **zero-filled** — every
     * parent is seeded to `0` first, then the query rows overlay it — restoring a
     * filtered-out parent to `0`, matching the in-memory witness. With an empty
     * criteria (the common no-relatedQuery case) no predicate is added and the count
     * is raw membership unchanged; the zero-fill is then idempotent (every parent has
     * a row). A relationship-existence filter ({@see WhereHas}/{@see WhereDoesntHave})
     * cannot route to the `related` alias — it re-roots on the count query's parent —
     * so it is rejected on this path (supply a custom provider).
     *
     * A polymorphic to-many is the same boundary as
     * {@see fetchRelatedCollection()}: its members span entity classes, so there is
     * no single related entity to count — it throws (supply a custom provider).
     *
     * @param list<object> $parents
     *
     * @return array<string, int>
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a relatedQuery filter key is not declared
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        if (\count($relation->relatedTypes()) > 1) {
            throw new \LogicException(\sprintf(
                'The %s does not support counting a polymorphic (morph-to-many) relationship "%s": its members span entity classes and cannot be one grouped COUNT. Supply a custom DataProvider that counts the related members across types.',
                self::class,
                $relation->name(),
            ));
        }

        if ($parents === []) {
            return [];
        }

        $parentClass = $this->entityClassFor($type);
        $parentMetadata = $this->entityManager->getClassMetadata($parentClass);
        $parentIdField = $parentMetadata->getSingleIdentifierFieldName();
        $property = $relation->column() ?? $relation->name();

        // Map each parent's storage id back to its wire id; the IN-clause binds the
        // storage ids, the result keys by the wire id.
        $encoder = $this->idEncoders->encoderFor($type);
        $storageToWire = [];
        foreach ($parents as $parent) {
            $storageKey = $parentMetadata->getIdentifierValues($parent)[$parentIdField] ?? null;
            if (!\is_scalar($storageKey)) {
                continue;
            }

            $wireId = $encoder !== null ? $encoder->encode($storageKey) : (string) $storageKey;
            $storageToWire[(string) $storageKey] = $wireId;
        }

        if ($storageToWire === []) {
            return [];
        }

        $relatedType = $relation->relatedTypes()[0] ?? null;
        $parentIds = \array_keys($storageToWire);

        // A relationship-existence filter re-roots on the count query's own root (the
        // parent), not the joined related entity, so it cannot scope the count to the
        // related alias — reject it on this path (the related-collection endpoint still
        // supports it; a custom provider can count it).
        $this->guardCountFilters($criteria, $relation);

        // A pivot-backed belongsToMany counts the ASSOCIATION-entity rows per parent
        // (so duplicate membership counts each row), grouped over the association
        // entity's parent FK — not the parent's own property, which is not a Doctrine
        // association for a through-entity pivot. A plain to-many counts the related
        // members via a left join on the parent property. Either way `$criteria`'s
        // filters land on the related members so the count reflects the relation's
        // filtered set (bundle ADR 0060); an empty criteria adds no predicate, so the
        // common no-filter count is the unchanged grouped query.
        if ($relatedType !== null && $this->supportsPivot($relatedType, $relation)) {
            $builder = $this->pivotCountQuery($relation, $parents[0], $relatedType, $parentIds, $criteria);
        } else {
            $builder = $this->entityManager->createQueryBuilder()
                ->select(\sprintf('parent.%s AS parent_id', $parentIdField))
                ->addSelect('COUNT(related) AS related_count')
                ->from($parentClass, 'parent')
                ->leftJoin(\sprintf('parent.%s', $property), 'related')
                ->where(\sprintf('parent.%s IN (:jsonapi_parent_ids)', $parentIdField))
                ->setParameter('jsonapi_parent_ids', $parentIds)
                ->groupBy(\sprintf('parent.%s', $parentIdField));

            // Apply the relation's relatedQuery filters on the `related` JOIN alias
            // (the default alias the count passes), never the `parent` root.
            $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler, 'related');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->getQuery()->getResult();

        // Zero-fill the whole page first: a parent whose filtered related set is empty
        // is dropped from the grouped result by the related-alias `andWhere` (an
        // `andWhere` on a LEFT JOIN excludes the non-matching parent), so seed every
        // parent to 0 and overlay the query rows — restoring a filtered-out parent to a
        // 0 count, matching the in-memory witness. With no filter every parent already
        // has a row, so the overlay is idempotent and the unfiltered count is unchanged.
        $counts = \array_fill_keys(\array_values($storageToWire), 0);

        foreach ($rows as $row) {
            $storageKey = $row['parent_id'] ?? null;
            if (!\is_scalar($storageKey)) {
                continue;
            }
            $wireId = $storageToWire[(string) $storageKey] ?? null;
            if ($wireId === null) {
                continue;
            }
            $count = $row['related_count'] ?? 0;
            $counts[$wireId] = \is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }

    /**
     * Whether the single related object of a monomorphic to-one survives `$criteria`'s
     * filters (bundle ADR 0068): a cheap `SELECT 1 FROM <relatedClass> related WHERE
     * related.<idField> = :id` probe with the shared {@see CriteriaApplier} driving the
     * {@see DoctrineFilterHandler} (so column/operator/cast semantics match the to-many
     * endpoint exactly), `setMaxResults(1)`, returning whether a row survived. Read-only —
     * no flush. The related type's query extensions apply so a scoped target
     * (soft-deleted / un-tenant / unpublished) reports a no-match exactly as a lazy load
     * would hide it.
     */
    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        $relatedClass = $this->entityClassFor($relatedType);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);
        $relatedIdField = $relatedMetadata->getSingleIdentifierFieldName();

        $targetId = $relatedMetadata->getIdentifierValues($related)[$relatedIdField] ?? null;
        if (!\is_scalar($targetId)) {
            return false;
        }

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS)
            ->andWhere(\sprintf('%s.%s = :jsonapi_to_one_id', self::ROOT_ALIAS, $relatedIdField))
            ->setParameter('jsonapi_to_one_id', $targetId);

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, new ExtensionContext($relatedType, QueryPurpose::FetchRelatedCollection, $request));
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        $builder->setMaxResults(1);

        return $builder->getQuery()->getOneOrNullResult() !== null;
    }

    /**
     * The batched to-one match over a page of parents (bundle ADR 0068): ONE
     * `SELECT related.<idField> … WHERE related.<idField> IN (:targetIds) AND <filters>`
     * query over the page's distinct to-one target ids (projected off each managed parent
     * with no proxy init, reusing {@see toOneTargetId()}/{@see parentWireId()}), then the
     * surviving id set is intersected per parent — O(1) store round-trips per relation,
     * not per parent. A parent whose to-one is `null` is a no-match (`false`) with no
     * query contribution. The related type's query extensions apply on the id-IN load so a
     * scoped target reports a no-match exactly as a lazy load would hide it.
     *
     * @param list<object> $parents
     *
     * @return array<string, bool>
     */
    public function relatedToOneMatchesBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        if ($parents === []) {
            return [];
        }

        $relatedType = $relation->relatedTypes()[0] ?? $parentType;
        $relatedClass = $this->entityClassFor($relatedType);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);
        $relatedIdField = $relatedMetadata->getSingleIdentifierFieldName();

        $property = $relation->column() ?? $relation->name();
        $parentMetadata = $this->entityManager->getClassMetadata($parents[0]::class);
        $encoder = $this->idEncoders->encoderFor($parentType);

        // Project each parent's to-one target id off the managed parent (a proxy exposes
        // its identifier without initialising — no round-trip), keyed by parent wire id. A
        // parent with a null to-one carries no target.
        $parentTargetId = [];
        $targetIds = [];
        foreach ($parents as $parent) {
            $wireId = $this->parentWireId($parent, $parentMetadata, $encoder);
            if ($wireId === null) {
                continue;
            }

            $targetId = $this->toOneTargetId($parent, $property, $relatedMetadata, $relatedIdField);
            $parentTargetId[$wireId] = $targetId;
            if ($targetId !== null) {
                $targetIds[(string) $targetId] = $targetId;
            }
        }

        $survivingIds = $this->matchingToOneTargetIds($relatedClass, $relatedType, $relatedIdField, $criteria, \array_values($targetIds), $request);

        $matches = [];
        foreach ($parentTargetId as $wireId => $targetId) {
            $matches[$wireId] = $targetId !== null && isset($survivingIds[(string) $targetId]);
        }

        return $matches;
    }

    /**
     * The set of `$targetIds` that survive `$criteria`'s filters, as a `storageId => true`
     * lookup, loaded in ONE `SELECT related.<idField> … WHERE related.<idField> IN
     * (:ids) AND <filters>` query driving the shared {@see DoctrineFilterHandler} (so the
     * filter semantics match the single probe and the to-many endpoint). Empty when there
     * are no target ids. The related type's query extensions apply too.
     *
     * @param class-string  $relatedClass
     * @param list<mixed>   $targetIds
     *
     * @return array<string, true>
     */
    private function matchingToOneTargetIds(
        string $relatedClass,
        string $relatedType,
        string $relatedIdField,
        CollectionCriteria $criteria,
        array $targetIds,
        ?JsonApiRequestInterface $request,
    ): array {
        if ($targetIds === []) {
            return [];
        }

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS)
            ->select(\sprintf('%s.%s', self::ROOT_ALIAS, $relatedIdField))
            ->andWhere(\sprintf('%s.%s IN (:jsonapi_to_one_ids)', self::ROOT_ALIAS, $relatedIdField))
            ->setParameter('jsonapi_to_one_ids', $targetIds);

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, new ExtensionContext($relatedType, QueryPurpose::FetchRelatedCollection, $request));
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        /** @var list<mixed> $rows */
        $rows = $builder->getQuery()->getScalarResult();

        $surviving = [];
        foreach ($rows as $row) {
            $id = \is_array($row) ? \reset($row) : $row;
            if (\is_scalar($id)) {
                $surviving[(string) $id] = true;
            }
        }

        return $surviving;
    }

    /**
     * Rejects a relationship-existence filter ({@see WhereHas}/{@see WhereDoesntHave})
     * in a `?withCount` count criteria: the count query roots on the parent and joins
     * the related entity as `related`, but a relationship-existence filter re-roots on
     * the query's own root entity, so routed to `related` it would scope the parent,
     * not the related members — the wrong semantics. It is supported on the
     * related-collection endpoint (which roots on the related entity); for the count,
     * supply a custom provider. The common related-attribute filters (`Where`,
     * `WhereIn`, …) apply correctly on the `related` join and are untouched.
     */
    private function guardCountFilters(CollectionCriteria $criteria, RelationInterface $relation): void
    {
        $requested = FilterDefaults::apply($criteria->queryParameters->filter, $criteria->filters);

        foreach (\array_keys($requested) as $key) {
            $filter = null;
            foreach ($criteria->filters as $declared) {
                if ($declared->key() === (string) $key) {
                    $filter = $declared;

                    break;
                }
            }

            if ($filter instanceof WhereHas || $filter instanceof WhereDoesntHave) {
                throw new \LogicException(\sprintf(
                    'The %s cannot apply a relationship-existence filter "%s" to a ?withCount count of "%s": the count roots on the parent, so the filter would scope the parent, not the related members. Supply a custom DataProvider to count this filtered relationship.',
                    self::class,
                    (string) $key,
                    $relation->name(),
                ));
            }
        }
    }

    /**
     * The grouped distinct-member count query for a pivot-backed `belongsToMany` over
     * its association entity: roots on the association entity, scopes to the page of
     * parents by the entity's parent-side `ManyToOne` FK, and groups by that FK so each
     * row is `parent_id => COUNT(DISTINCT far member)`.
     *
     * The count is over **distinct far members** (`COUNT(DISTINCT pivot.<farProperty>)`),
     * NOT association rows — so a member joined to the parent by more than one
     * association row (a track at two positions) contributes ONCE. This matches the
     * related-collection endpoint, which groups one row per distinct far member
     * ({@see count()} counts `DISTINCT` too) and renders deduped linkage: the
     * `?withCount` relationship-object total and the endpoint pagination total are the
     * one consistent `total` semantic the contract requires (bundle ADR 0052).
     *
     * When `$criteria` carries a related filter (a `relatedQuery` filter on this
     * relation, bundle ADR 0060), the far member is joined as `related` and the filter
     * is applied on it, so the distinct-member count reflects the relation's filtered
     * set exactly as the endpoint pages it; with no filter the far join is omitted and
     * the query is the unchanged distinct-member count (the budget is preserved for the
     * common case).
     *
     * @param list<int|string> $parentIds the parent storage keys (the IN-clause binds them)
     */
    private function pivotCountQuery(RelationInterface $relation, object $parent, string $relatedType, array $parentIds, CollectionCriteria $criteria): QueryBuilder
    {
        $relatedClass = $this->entityClassFor($relatedType);
        $association = $this->pivotAssociation($relation, $parent, $relatedClass);

        // Join the parent-side ManyToOne so the count can GROUP BY the parent's id
        // field — DQL cannot GROUP BY an IDENTITY() expression (only a path or a
        // result variable), and grouping by the SELECT alias is not portable.
        $parentClass = $this->entityManager
            ->getClassMetadata($association->entityClass)
            ->getAssociationTargetClass($association->parentProperty);
        $parentIdField = $this->entityManager->getClassMetadata($parentClass)->getSingleIdentifierFieldName();

        $builder = $this->entityManager->createQueryBuilder()
            ->select(\sprintf('parent.%s AS parent_id', $parentIdField))
            // DISTINCT far members so duplicate membership counts once, matching the
            // endpoint's deduped total and rendered linkage (bundle ADR 0052).
            ->addSelect(\sprintf('COUNT(DISTINCT pivot.%s) AS related_count', $association->farProperty))
            ->from($association->entityClass, 'pivot')
            ->innerJoin(\sprintf('pivot.%s', $association->parentProperty), 'parent')
            ->where(\sprintf('parent.%s IN (:jsonapi_parent_ids)', $parentIdField))
            ->setParameter('jsonapi_parent_ids', $parentIds)
            ->groupBy(\sprintf('parent.%s', $parentIdField));

        // A related filter needs the far member to scope against, so join it as
        // `related` (the count's default alias) and apply the filters there — the far
        // join is added ONLY when there is a filter, so the unfiltered count keeps its
        // original distinct-member query and budget (bundle ADR 0060).
        if (FilterDefaults::apply($criteria->queryParameters->filter, $criteria->filters) === []) {
            return $builder;
        }

        $builder->innerJoin(\sprintf('pivot.%s', $association->farProperty), 'related');

        return $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler, 'related');
    }

    // --- pivot (belongsToMany association-entity) collection -------------------

    public function supportsPivot(string $relatedType, RelationInterface $relation): bool
    {
        return $this->pivotAssociations !== null
            && $this->pivotAssociations->isPivotRelation($relation)
            && isset($this->entityClassByType[$relatedType]);
    }

    /**
     * The related collection over the pivot's association entity, in ONE DQL
     * statement. The far (related) entity is the OUTER query root, the association
     * entity is joined as `pivot` correlated on its far-side `ManyToOne` and scoped
     * to the parent by its parent-side `ManyToOne`, and each declared pivot field is
     * selected as a scalar alias:
     *
     *     SELECT resource, pivot.<field1> AS pivot_<field1>, …
     *     FROM <FarEntity> resource
     *     INNER JOIN <AssocEntity> pivot WITH pivot.<farProp> = resource
     *     WHERE pivot.<parentProp> = :jsonapi_parent
     *       [AND <related-entity filters on resource>]   -- root alias, shared handler
     *       [AND <pivot filters on pivot.<field>>]        -- pivot alias, this query
     *     ORDER BY [<pivot/related sorts>]
     *     LIMIT/OFFSET
     *
     * Rooting on the far entity lets the shared {@see CriteriaApplier} +
     * {@see DoctrineFilterHandler}/{@see DoctrineSortHandler} apply the related
     * vocabulary on the root exactly as the plain related collection does; pivot
     * keys are split out and applied on the `pivot` alias here. The scalar pivot
     * fields ride each hydrated row (a "mixed" result: `[0 => farEntity,
     * 'pivot_<field>' => value]`), so the per-member map comes from the same pass —
     * no separate read, no page-shortening, and the window applies per far-entity
     * row so pagination is correct.
     *
     * @return PivotCollectionResult<object>
     */
    public function fetchRelatedPivotCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): PivotCollectionResult {
        $builder = $this->pivotQuery($relatedType, $parent, $relation);

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, new ExtensionContext($relatedType, QueryPurpose::FetchRelatedCollection, $request));
        }

        // The criteria already carries the merged related+pivot filter/sort vocab AND
        // the pivot-key→`pivot`-alias routing map (RelationCriteriaFactory populated
        // both for this endpoint), so the shared alias-aware applier owns the WHOLE
        // pivot vocabulary in one pass — related keys on the root, pivot keys on the
        // joined `pivot` alias — replacing the hand-rolled split (bundle ADR 0059).
        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        $window = $criteria->window;
        if ($window === null) {
            return $this->pivotResult($this->pivotRows($builder), $relatedType, $relation, null);
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        // The pivot tail stays hand-rolled rather than delegating to the shared
        // WindowExecutor (core ADR 0061): the executor is generic over OBJECT entities,
        // but a pivot fetch windows over "mixed" rows (each `[0 => farEntity,
        // 'pivot_<field>' => value]`), and the far-entity windowing cannot be separated
        // from the per-member pivot map — they ride the SAME grouped query. So this
        // path mirrors the executor's branches in-place (the same count-free probe /
        // countable count), keeping it behaviour-identical until the pivot row shape is
        // reconciled with the executor's entity contract.
        //
        // Count-free by default (G21): the pivot endpoint counts only when the handler
        // resolved a COUNT for this fetch (`$criteria->wantsCount` — the relation
        // paginator's withCount() author opt-in, or ?withCount=_self_ under a
        // countable() relation; the handler 400s an un-countable `_self_` first).
        // Otherwise it paginates count-FREE: no COUNT runs; the page is over-fetched by
        // one (limit+1) and the surplus far member signals a further page, driving the
        // count-free page's `next` link without a total (bundle ADR 0052).
        if (!$criteria->wantsCount) {
            return $this->countFreePivotPage($builder, $window, $relatedType, $relation);
        }

        $total = $this->count($builder, distinct: true);
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit);

        return $this->pivotResult($this->pivotRows($builder), $relatedType, $relation, $total);
    }

    /**
     * The count-free page for a non-countable pivot relation — the count-free branch
     * of the shared {@see WindowExecutor}, mirrored in-place over the pivot query (the
     * executor is generic over object entities, so the pivot's "mixed" rows cannot ride
     * it; see {@see fetchRelatedPivotCollection()}). The window is applied with `LIMIT
     * $limit + 1` (one far member past the page), so the surplus row proves a further
     * page exists without any `COUNT`. The pivot query already groups one row per
     * distinct far member (see {@see pivotQuery()}), so the over-fetch and slice are
     * over distinct members. The surplus is dropped before the pivot map is built; the
     * result carries a `null` total with {@see PivotCollectionResult::$windowed} true so
     * the handler builds a count-free page (bundle ADR 0052).
     *
     * @return PivotCollectionResult<object>
     */
    private function countFreePivotPage(
        QueryBuilder $builder,
        OffsetWindow $window,
        string $relatedType,
        RelationInterface $relation,
    ): PivotCollectionResult {
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit + 1);
        $rows = $this->pivotRows($builder);

        $hasMore = \count($rows) > $window->limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $window->limit);
        }

        return $this->pivotResult($rows, $relatedType, $relation, total: null, windowed: true, hasMore: $hasMore);
    }

    /**
     * The pivot map for EVERY member of the parent's pivot relation (no filter, no
     * window) — for the relationship-linkage endpoint, which renders all linkage off
     * the parent.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelatedPivotMap(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
    ): array {
        $builder = $this->pivotQuery($relatedType, $parent, $relation);

        return $this->pivotResult($this->pivotRows($builder), $relatedType, $relation, null)->pivotMap;
    }

    /**
     * The EXISTING pivot meta for the parent's pivot relation — the validation
     * read-seam (ADR 0050). It reuses the same association-entity projection the
     * relationship-linkage endpoint reads ({@see fetchRelatedPivotMap()}): one DQL
     * statement over the association entity, each row's writable/readOnly pivot column
     * cast to its wire value, keyed by the related (far) member's id. The validator
     * folds an existing member's row under the incoming linkage meta so a partial
     * pivot update on an existing member validates in the update (preserved-value)
     * context while a genuinely-new member validates in create context.
     *
     * Returns `[]` when the relation is not a pivot-backed `belongsToMany` this
     * provider can resolve over an association entity — there is then no stored pivot,
     * so every incoming member validates as new (create context).
     *
     * @return array<string, array<string, mixed>> `relatedId => [pivotField => wire value]`
     */
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$this->supportsPivot($relatedType, $relation)) {
            return [];
        }

        return $this->fetchRelatedPivotMap($relatedType, $parent, $relation);
    }

    /**
     * The base pivot query: the far entity rooted at {@see ROOT_ALIAS}, the
     * association entity inner-joined as `pivot` (correlated on its far-side
     * `ManyToOne`, scoped to the parent on its parent-side `ManyToOne`), and each
     * declared pivot field selected as a `pivot_<field>` scalar alias so the values
     * ride each hydrated row.
     *
     * The query `GROUP BY`s the far entity id so it returns exactly ONE row per
     * distinct far member. This keeps pagination correct and the pivot map sound
     * under **duplicate membership** — the same far entity joined to the parent by
     * more than one association-entity row (a track added to a playlist at two
     * positions). Without the grouping the inner join fans that member out one row
     * per pivot row, so a windowed `LIMIT`/`OFFSET` would split a member across pages
     * and the pivot map (keyed by member id) would last-row-win; grouped, the window
     * is over distinct members and each member yields a single representative pivot
     * row. The pivot relation therefore renders **one** membership's values per
     * member: where a member appears more than once the rendered pivot meta reflects
     * a representative row (the contract — pivot meta is a single per-member value
     * set, not a list; ADR 0045).
     */
    private function pivotQuery(string $relatedType, object $parent, RelationInterface $relation): QueryBuilder
    {
        $relatedClass = $this->entityClassFor($relatedType);
        $association = $this->pivotAssociation($relation, $parent, $relatedClass);
        $idField = $this->entityManager->getClassMetadata($relatedClass)->getSingleIdentifierFieldName();

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS)
            ->innerJoin(
                $association->entityClass,
                'pivot',
                'WITH',
                \sprintf('pivot.%s = %s', $association->farProperty, self::ROOT_ALIAS),
            )
            ->andWhere(\sprintf('pivot.%s = :jsonapi_parent', $association->parentProperty))
            ->setParameter('jsonapi_parent', $parent)
            // One row per distinct far member — see the docblock (duplicate membership).
            ->groupBy(\sprintf('%s.%s', self::ROOT_ALIAS, $idField));

        foreach (PivotFields::declaredFor($relation) as $field) {
            // A hidden pivot field (core hidden()) is never rendered in the pivot
            // meta, so it is not selected here — it can still be filtered/sorted,
            // which reads its column on the `pivot` alias directly, not this scalar.
            if ($field->isHidden()) {
                continue;
            }
            // Select the backing column under a `pivot_<name>` alias keyed by the
            // wire name (column defaults to the name, but may differ via storedAs()).
            $builder->addSelect(\sprintf('pivot.%s AS pivot_%s', $field->column() ?? $field->name(), $field->name()));
        }

        return $builder;
    }

    /**
     * Runs the pivot query and returns the "mixed" result rows — each a
     * `[0 => farEntity, 'pivot_<field>' => value, …]` map (the far entity hydrated at
     * index 0, the scalar pivot fields under their `pivot_<field>` aliases).
     *
     * @return list<array<int|string, mixed>>
     */
    private function pivotRows(QueryBuilder $builder): array
    {
        /** @var list<array<int|string, mixed>> $rows */
        $rows = $builder->getQuery()->getResult();

        return $rows;
    }

    /**
     * Builds the {@see PivotCollectionResult} from the query rows: the far entities
     * (row index 0) are the items, and the pivot map is `wireId => [field => typed
     * value]` read from each row's `pivot_<field>` scalar aliases and cast per the
     * relation's declared field types.
     *
     * @param list<array<int|string, mixed>> $rows
     *
     * @return PivotCollectionResult<object>
     */
    private function pivotResult(
        array $rows,
        string $relatedType,
        RelationInterface $relation,
        ?int $total,
        bool $windowed = false,
        bool $hasMore = false,
    ): PivotCollectionResult {
        $pivotFields = PivotFields::byName($relation);
        $encoder = $this->idEncoders->encoderFor($relatedType);
        $relatedMetadata = $this->entityManager->getClassMetadata($this->entityClassFor($relatedType));
        $idField = $relatedMetadata->getSingleIdentifierFieldName();

        $items = [];
        $pivotMap = [];
        foreach ($rows as $row) {
            $far = $row[0] ?? null;
            if (!\is_object($far)) {
                continue;
            }
            $items[] = $far;

            $identifierValues = $this->entityManager->getClassMetadata($far::class)->getIdentifierValues($far);
            $storageKey = $identifierValues[$idField] ?? null;
            if ($storageKey === null) {
                continue;
            }

            if ($encoder !== null) {
                $wireId = $encoder->encode($storageKey);
            } elseif (\is_scalar($storageKey)) {
                $wireId = (string) $storageKey;
            } else {
                continue;
            }

            $values = [];
            foreach ($pivotFields as $name => $field) {
                // A hidden pivot field is filterable/sortable but never rendered in
                // the relationship meta (core hidden()); it was not selected above.
                if ($field->isHidden()) {
                    continue;
                }
                // The scalar was selected under the `pivot_<name>` alias (keyed by
                // wire name) in pivotQuery(); cast it through the field's own type.
                $values[$name] = PivotFields::cast($row['pivot_' . $name] ?? null, $field);
            }

            $pivotMap[$wireId] = $values;
        }

        return new PivotCollectionResult($items, $pivotMap, $total, $windowed, $hasMore);
    }

    /**
     * Resolves the relation's association entity, asserting the resolver is wired
     * (it always is under Doctrine — `supportsPivot()` already gated on it).
     *
     * @param class-string $relatedClass
     */
    private function pivotAssociation(RelationInterface $relation, object $parent, string $relatedClass): PivotAssociation
    {
        \assert($this->pivotAssociations !== null);

        return $this->pivotAssociations->resolve($relation, $parent, $relatedClass);
    }

    /**
     * @return list<object>
     */
    private function items(QueryBuilder $builder): array
    {
        /** @var list<object> */
        return $builder->getQuery()->getResult();
    }

    /**
     * The total of the filtered collection: the same builder re-selected as a
     * `COUNT` of the root entity, with ordering dropped (it cannot change the
     * count) and no window applied.
     *
     * With `$distinct` set the count is the **pivot** variant: a
     * `COUNT(DISTINCT …)` of the far-entity root with both ordering AND grouping
     * dropped, so the total counts distinct far members rather than joined pivot
     * rows — the page is grouped to one row per member (see {@see pivotQuery}),
     * so the total must match it under duplicate membership (a member joined by
     * more than one association-entity row). The scalar pivot selects and the
     * `GROUP BY` must be cleared too — a `COUNT` query carries a single ungrouped
     * select.
     */
    private function count(QueryBuilder $builder, bool $distinct = false): int
    {
        $counter = clone $builder;
        $counter->resetDQLPart('orderBy');

        if ($distinct) {
            $counter->resetDQLPart('groupBy');
            $counter->select(\sprintf('COUNT(DISTINCT %s)', self::ROOT_ALIAS));
        } else {
            $counter->select(\sprintf('COUNT(%s)', self::ROOT_ALIAS));
        }

        $total = $counter->getQuery()->getSingleScalarResult();

        return \is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @return class-string
     */
    private function entityClassFor(string $type): string
    {
        return $this->entityClassByType[$type]
            ?? throw new \LogicException(\sprintf('No Doctrine entity class is mapped for JSON:API type "%s".', $type));
    }

    /**
     * The extensions whose {@see DoctrineExtensionInterface::supports()} is
     * true for `$type`, preserving the injected (priority) order.
     *
     * @return list<DoctrineExtensionInterface>
     */
    private function extensionsFor(string $type): array
    {
        return \array_values(\array_filter(
            $this->extensions,
            static fn(DoctrineExtensionInterface $extension): bool => $extension->supports($type),
        ));
    }
}
