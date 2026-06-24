<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiBundle\DataProvider\Keyset\CursorTokenMinter;
use haddowg\JsonApiBundle\DataProvider\Keyset\InMemoryKeyset;
use haddowg\JsonApiBundle\DataProvider\Keyset\KeysetColumn;
use haddowg\JsonApiBundle\DataProvider\Keyset\KeysetResolver;

/**
 * An in-memory read provider: a test double and conformance witness. It reads
 * from a per-type {@see InMemoryStore} of domain objects keyed by id and answers
 * `fetchOne()` / `fetchCollection()` straight from it, so a slice runs with zero
 * database.
 *
 * Collections run the same {@see CriteriaApplier} matching as the Doctrine
 * provider, executed through core's reference in-memory handlers
 * ({@see ArrayFilterHandler} / {@see ArraySortHandler}) with an `array_slice`
 * for the pagination window — so a spec test failing on one provider but not
 * the other localizes the bug to that provider's *execution*.
 *
 * It lives in `src/` (not `tests/`) so it is reusable as a documented worked
 * example, mirroring how core ships its `InMemory\Array{Filter,Sort}Handler`.
 * One instance answers for a single `$type`. To make a slice writable, pass an
 * identifier closure and hand {@see store()} to an
 * {@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister}; the two then
 * share one store, so writes are immediately readable.
 *
 * @implements DataProviderInterface<object>
 */
final class InMemoryDataProvider implements DataProviderInterface
{
    private readonly InMemoryStore $store;

    private readonly CriteriaApplier $applier;

    private readonly WindowExecutor $windowExecutor;

    private readonly ArrayFilterHandler $filterHandler;

    private readonly ArraySortHandler $sortHandler;

    private readonly KeysetResolver $keysetResolver;

    private readonly InMemoryKeyset $keyset;

    private readonly CursorTokenMinter $minter;

    /**
     * @param iterable<int|string, object>         $items    objects keyed by id
     * @param (\Closure(object): string)|null      $identify reads an item's id; required only if a
     *                                                       persister writes through {@see store()}
     * @param (\Closure(object, string): void)|null $assignId writes a minted id onto an item; pass it to
     *                                                       make the shared store assign store-provided
     *                                                       (auto-increment) ids on an id-less create
     * @param string                               $idColumn the model member the cursor (keyset) page reads as the
     *                                                       primary-key tiebreaker (via core's `Accessor`); defaults to `id`
     * @param iterable<ArrayFilterArmInterface>    $filterArms author arms for custom `FilterInterface` types (this provider is
     *                                                         hand-constructed, so arms are passed here rather than DI-tagged)
     * @param iterable<ArraySortArmInterface>      $sortArms   author arms for custom `SortInterface` types
     * @param ?InMemorySnapshotCoordinator         $coordinator coordinates a cross-store single-pass snapshot for atomic
     *                                                          rollback; pass the SAME instance to every related store so
     *                                                          a rollback preserves cross-store object identity
     */
    public function __construct(
        private readonly string $type,
        iterable $items,
        ?\Closure $identify = null,
        ?\Closure $assignId = null,
        private readonly string $idColumn = 'id',
        iterable $filterArms = [],
        iterable $sortArms = [],
        ?InMemorySnapshotCoordinator $coordinator = null,
    ) {
        $this->store = new InMemoryStore($items, $identify, $assignId, $coordinator);
        $this->applier = new CriteriaApplier();
        $this->windowExecutor = new WindowExecutor();
        $this->filterHandler = new ArrayFilterHandler($filterArms);
        $this->sortHandler = new ArraySortHandler($sortArms);
        $this->keysetResolver = new KeysetResolver();
        $this->keyset = new InMemoryKeyset();
        $this->minter = new CursorTokenMinter(new CursorCodec());
    }

    /**
     * The backing store, shared with an {@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister}
     * to make this type writable.
     */
    public function store(): InMemoryStore
    {
        return $this->store;
    }

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->store->find($id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        // A cursor (keyset) window is its own execution: the keyset builds its OWN
        // NULL=largest order and the strictly-after predicate, so the plain sort
        // handler is bypassed (it omits the null-forcing + the PK tiebreak). The
        // filters still apply (and `?sort` is still validated against the
        // vocabulary by the keyset resolver); an OffsetWindow / null window stays
        // on the shared executor tail, byte-identical to before.
        if ($criteria->window instanceof CursorWindow) {
            return $this->runCursor($criteria, $this->store->all());
        }

        // Count-free by default (G21): count the pre-window total only when the
        // handler resolved a COUNT for this fetch (the paginator's withCount() author
        // opt-in, or ?withCount=_self_ under a countable() resource); otherwise the
        // executor fetches count-free via the window+1 probe and reports `hasMore`
        // (bundle ADR 0075).
        return $this->applyAndWindow($criteria, $this->store->all(), countable: $criteria->wantsCount);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        // Read the related objects off the parent via the relation's public
        // accessor (honours storedAs/extractUsing; the default accessor returns
        // the stored related collection), then run the same criteria pipeline as
        // a primary collection fetch. Count-free by default (G21): the related
        // endpoint counts only when the handler resolved a COUNT for this fetch
        // (`$criteria->wantsCount` — the relation paginator's withCount() author
        // opt-in, or ?withCount=_self_ under a countable() relation); otherwise it
        // paginates count-free (no total, the page driven by a limit+1 probe).
        // A to-many whose related value is null/absent (an unset association) is an
        // empty collection here: the whole-association linkage render handled a null
        // to-many gracefully, so the queryable related/relationship fetch path that
        // now routes through here must too — normalise via asIterator() (null/scalar →
        // empty), mirroring countRelated() rather than asserting is_iterable.
        $related = $relation->readValue($parent, $request);
        $items = \is_array($related)
            ? \array_values($related)
            : \iterator_to_array($this->asIterator($related), false);

        return $this->applyAndWindow($criteria, $items, $criteria->wantsCount);
    }

    /**
     * The in-memory witness for the batched related fetch (bundle ADR 0061): for
     * each parent it reads the related object(s) off the parent through the relation's
     * public accessor, applies `$criteria`'s filters/sorts via the shared
     * {@see CriteriaApplier}, and runs the same {@see \haddowg\JsonApi\Collection\WindowExecutor}
     * tail as {@see fetchRelatedCollection()} — keyed by the parent's wire id. This is
     * {@see fetchRelatedCollection()} lifted to a per-parent loop, so the per-parent
     * result is byte-identical to a one-at-a-time fetch; the Doctrine provider runs the
     * structurally-equivalent IN-fetch + PHP partition (Approach B). A parent the store
     * cannot identify is skipped (it has no wire id to key on); a polymorphic to-many's
     * mixed members are still a single iterable off the parent, so they window like any
     * other (the documented in-memory support, vs the Doctrine boundary).
     *
     * A **to-one** relation is the {@see \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher}
     * include arm (bundle ADR 0062): each parent's {@see RelationInterface::readValue()}
     * is the single related object (or `null`), wrapped as a 0-or-1
     * {@see CollectionResult} keyed by the parent's wire id — no window, no criteria
     * (a to-one include has neither). The orchestrator writes back `items[0] ?? null`
     * onto the to-one column, so the rendered linkage is unchanged.
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
        $results = [];
        foreach ($parents as $parent) {
            $wireId = $this->store->idOf($parent);
            if ($wireId === null) {
                continue;
            }

            // A to-one relation reads a single object (or null) off the parent — wrap
            // it as a 0-or-1 result rather than asserting it is iterable.
            if (!$relation->isToMany()) {
                $related = $relation->readValue($parent, $request);
                $results[$wireId] = new CollectionResult(\is_object($related) ? [$related] : []);

                continue;
            }

            // A WINDOWED to-many include appends a deterministic PK tiebreak to the sort
            // so ties resolve by id (not PHP-usort insertion order) — the SAME tiebreak
            // the Doctrine native ROW_NUMBER batch appends to its ORDER BY (bundle ADR
            // 0065), so the two providers are provably identical on ties, not
            // coincidentally identical when insertion order happens to equal PK order.
            // A plain (un-windowed) include carries no tiebreak, so it stays byte-identical
            // to before.
            $batchCriteria = $criteria->window instanceof OffsetWindow
                ? $this->withPkTiebreak($criteria)
                : $criteria;

            $results[$wireId] = $this->fetchRelatedCollection(
                $relation->relatedTypes()[0] ?? $parentType,
                $parent,
                $relation,
                $batchCriteria,
                $request,
            );
        }

        return new RelatedBatch($results);
    }

    /**
     * Returns `$criteria` with a final PK-tiebreak sort appended (the in-memory witness's
     * half of the windowed-batch determinism fix, bundle ADR 0065): a synthetic `id` sort
     * is added to the declared vocabulary AND to the resolved order (after the requested
     * sort, or after the resource default when none was requested), so the shared
     * {@see CriteriaApplier} cascades it as the least-significant key — the same final
     * `id ASC` level the Doctrine native ORDER BY appends. The tiebreak never overrides an
     * explicit sort; it only orders rows tied on every requested/default column.
     */
    private function withPkTiebreak(CollectionCriteria $criteria): CollectionCriteria
    {
        $tiebreak = new SortByField('__jsonapi_pk_tiebreak', $this->idColumn);

        $requested = $criteria->queryParameters->sort;
        $sort = [...$requested, $tiebreak->key()];

        // The default sort applies only when no sort was requested; append the tiebreak
        // to it too so a default-ordered window is tiebroken the same way.
        $defaultSort = $requested === []
            ? [...$criteria->defaultSort, new SortDirective($tiebreak, descending: false)]
            : $criteria->defaultSort;

        return new CollectionCriteria(
            new QueryParameters(
                $criteria->queryParameters->fields,
                $criteria->queryParameters->includes,
                sort: $sort,
                filter: $criteria->queryParameters->filter,
                pagination: $criteria->queryParameters->pagination,
            ),
            $criteria->filters,
            sorts: [...$criteria->sorts, $tiebreak],
            window: $criteria->window,
            defaultSort: $defaultSort,
            aliasOf: $criteria->aliasOf,
            // Preserve the count decision through the tiebreak rebuild (G21): a
            // windowed include of a countable relation counts (§6d), so the rebuilt
            // criteria must carry the same wantsCount the batcher resolved.
            wantsCount: $criteria->wantsCount,
        );
    }

    /**
     * The in-memory witness — and the definition of correct `?withCount` behaviour —
     * counts each parent's **filtered** related set: it reads the related objects off
     * every parent through the relation's public accessor, applies `$criteria`'s
     * filters via the shared {@see CriteriaApplier} (the same matching the related
     * endpoint runs), and counts the survivors, keying by the parent's wire id (bundle
     * ADR 0052/0060). With an empty criteria (the common no-relatedQuery case) every
     * member survives, so the count is raw membership unchanged; a parent whose
     * filtered set is empty reports `0`. A polymorphic to-many is supported here — the
     * mixed members are still a single iterable off the parent, so they count like any
     * other (the documented in-memory support, vs the Doctrine boundary). A parent the
     * store cannot identify is skipped.
     *
     * @param list<object> $parents
     *
     * @return array<string, int>
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        $counts = [];
        foreach ($parents as $parent) {
            $id = $this->store->idOf($parent);
            if ($id === null) {
                continue;
            }

            $related = $relation->readValue($parent, $request);
            $items = \is_array($related)
                ? \array_values($related)
                : \iterator_to_array($this->asIterator($related), false);

            // Apply the relation's relatedQuery filters over the array exactly as a
            // related-collection fetch would; an empty criteria leaves the set whole.
            // No window/sort is carried (a count needs no page or order), so this only
            // ever filters.
            $filtered = $this->applier->apply($criteria, $items, $this->filterHandler, $this->sortHandler);

            $counts[$id] = \count($filtered);
        }

        return $counts;
    }

    /**
     * The in-memory witness for the single to-one match (bundle ADR 0068): wrap the one
     * related object in a 1-element list and run the same {@see CriteriaApplier} filter
     * pass {@see countRelated()} runs over a parent's related set (filter only — no
     * window/sort, which are irrelevant to a single member), returning whether the object
     * survived. An unknown filter key `400`s in the applier exactly as the to-many
     * endpoint.
     */
    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        return $this->matchesOne($criteria, $related);
    }

    /**
     * The BATCHED in-memory witness for a to-one match over a page of parents (bundle ADR
     * 0068): read each parent's single to-one target off the parent via the relation's
     * public accessor and run the same 1-element {@see CriteriaApplier} match per object,
     * keyed by the parent's wire id. A parent whose to-one is `null` is a no-match
     * (`false`); a parent the store cannot identify is skipped (it has no wire id to key
     * on). This loops in PHP exactly as {@see relatedToOneMatches()} does per object, so a
     * batched result is byte-identical to a one-at-a-time probe.
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
        $matches = [];
        foreach ($parents as $parent) {
            $wireId = $this->store->idOf($parent);
            if ($wireId === null) {
                continue;
            }

            $related = $relation->readValue($parent, $request);
            $matches[$wireId] = \is_object($related) && $this->matchesOne($criteria, $related);
        }

        return $matches;
    }

    /**
     * Whether the single `$related` object survives `$criteria`'s filters — the shared
     * body of {@see relatedToOneMatches()} and {@see relatedToOneMatchesBatch()}: apply
     * the criteria (filter only, the window/sort irrelevant to one member) over a
     * 1-element list and report whether it survived.
     */
    private function matchesOne(CollectionCriteria $criteria, object $related): bool
    {
        $filtered = $this->applier->apply($criteria, [$related], $this->filterHandler, $this->sortHandler);

        return \count($filtered) === 1;
    }

    /**
     * Normalizes a relation's read value to an iterator so a non-countable Traversable
     * can be drained by {@see \iterator_to_array()}. A non-iterable value (an empty
     * to-many that read as null/scalar) becomes an empty iterator (count 0).
     */
    private function asIterator(mixed $related): \Iterator
    {
        if ($related instanceof \Iterator) {
            return $related;
        }

        if ($related instanceof \Traversable) {
            return new \IteratorIterator($related);
        }

        return new \ArrayIterator([]);
    }

    /**
     * The in-memory store holds no pivot data — a pivot column needs an association
     * entity the in-memory provider cannot model (the documented in-memory pivot
     * boundary, mirrored on the write side by {@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister}).
     * So it returns no existing pivot meta: the validator then treats every incoming
     * member as new (create context), exactly as before the pivot merge-before-validate
     * (ADR 0050).
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return [];
    }

    /**
     * The in-memory cursor (keyset) execution — the **ground truth** the Doctrine
     * push-down matches byte-for-byte (bundle ADR 0063). It resolves the keyset
     * columns (the active sort + the appended/deduped PK; validates `?sort`),
     * applies the filters, checks the cursor against the resolved columns (a stale
     * cursor → 400), then runs {@see InMemoryKeyset}: sort by the forced
     * NULL=largest order, keep the rows strictly after the boundary, over-fetch
     * `limit + 1`, slice, and (for a backward page) flip the directions and
     * reverse. Tokens are minted off the sliced page via the shared
     * {@see CursorTokenMinter}.
     *
     * @param list<object> $items
     *
     * @return CursorCollectionResult<object>
     */
    private function runCursor(CollectionCriteria $criteria, array $items): CursorCollectionResult
    {
        $window = $criteria->window;
        \assert($window instanceof CursorWindow);

        // Resolve the keyset columns (the active sort + the PK), validating `?sort`
        // against the vocabulary exactly as the plain path does. The PK direction
        // for a PK-only keyset follows the resource default-sort-on-PK; with none
        // it is ascending (the resolver's default).
        $columns = $this->keysetResolver->resolve($criteria, $this->idColumn);

        // Apply the FILTERS only — the keyset owns the order, so the plain sort is
        // never applied (a sort-stripped criteria leaves the filter application
        // untouched and adds no ordering).
        $items = $this->applyFiltersOnly($criteria, $items);

        // page[before] wins over page[after]: a backward page flips the column
        // directions (which, under NULL=largest, flips the null bucket too) and
        // the after-predicate, so "strictly after under the reversed order" means
        // "strictly before under the natural order".
        $backward = $window->before !== null;
        $boundary = $backward ? $window->before : $window->after;
        $orderColumns = $backward ? $this->flip($columns) : $columns;

        if ($boundary !== null) {
            $parameter = $backward ? 'page[before]' : 'page[after]';
            $this->keysetResolver->assertFresh($boundary, $columns, $parameter);
        }

        $sorted = $this->keyset->sort($items, $orderColumns);
        if ($boundary !== null) {
            $sorted = $this->keyset->after($sorted, $boundary, $orderColumns);
        }

        // Over-fetch by one: the surplus proves a further page (forward → next,
        // backward → prev). Slice to the limit, then re-orient a backward page to
        // natural forward order for rendering.
        $hasSurplus = \count($sorted) > $window->limit;
        $page = \array_slice($sorted, 0, $window->limit);
        if ($backward) {
            $page = \array_reverse($page);
        }

        return $this->minter->mint(
            $window,
            $columns,
            \array_values($page),
            $hasSurplus,
            static fn(object $row, string $column): string|int|float|bool|null => CursorTokenMinter::coerce(Accessor::get($row, $column)),
        );
    }

    /**
     * Applies the criteria's FILTERS to `$items` (never the sort — the keyset owns
     * the order). A sort-stripped, window-less criteria reuses the shared
     * {@see CriteriaApplier} so the filter semantics are identical to a plain
     * fetch; the empty sort + default sort means the applier adds no ordering.
     *
     * @param list<object> $items
     *
     * @return list<object>
     */
    private function applyFiltersOnly(CollectionCriteria $criteria, array $items): array
    {
        $filterOnly = new CollectionCriteria(
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
        );

        /** @var list<object> $applied */
        $applied = $this->applier->apply($filterOnly, $items, $this->filterHandler, $this->sortHandler);

        return $applied;
    }

    /**
     * The keyset columns with every direction flipped — the backward-page order
     * (which, under NULL=largest, also flips the null-bucket placement because the
     * comparator reads the per-column direction directly).
     *
     * @param list<KeysetColumn> $columns
     *
     * @return list<KeysetColumn>
     */
    private function flip(array $columns): array
    {
        return \array_map(
            static fn(KeysetColumn $column): KeysetColumn => new KeysetColumn($column->column, !$column->descending),
            $columns,
        );
    }

    /**
     * Applies `$criteria` (filter + sort) to `$items` through core's reference
     * in-memory handlers, then delegates the window/count/count-free tail to the
     * shared {@see WindowExecutor} (core ADR 0061) over `array_slice`/`count`
     * closures — the shared tail of {@see fetchCollection()} and
     * {@see fetchRelatedCollection()}.
     *
     * When `$countable` is false (a non-countable related to-many, bundle ADR 0052)
     * the executor builds the window **count-free**: the result carries no total,
     * only a `hasMore` flag derived from a limit+1 probe — so the handler renders a
     * count-free page (no `total`/`last`). A primary collection and a countable
     * relation pass `$countable` true and carry the pre-window total as before. The
     * in-memory `count(items) > offset + count(page)` form is equivalent to the
     * executor's limit+1 probe.
     *
     * @param list<mixed> $items
     *
     * @return CollectionResult<object>
     */
    private function applyAndWindow(CollectionCriteria $criteria, array $items, bool $countable): CollectionResult
    {
        /** @var list<object> $items */
        $items = $this->applier->apply(
            $criteria,
            $items,
            $this->filterHandler,
            $this->sortHandler,
        );

        return $this->windowExecutor->run(
            $criteria->window,
            $countable,
            all: static fn(): array => $items,
            count: static fn(): int => \count($items),
            page: static fn(int $offset, int $limit): array => \array_slice($items, $offset, $limit),
            probe: static fn(int $offset, int $limit): array => \array_slice($items, $offset, $limit),
        );
    }
}
