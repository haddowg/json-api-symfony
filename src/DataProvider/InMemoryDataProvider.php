<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;

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

    private readonly ArrayFilterHandler $filterHandler;

    private readonly ArraySortHandler $sortHandler;

    /**
     * @param iterable<int|string, object>    $items    objects keyed by id
     * @param (\Closure(object): string)|null $identify reads an item's id; required only if a
     *                                                  persister writes through {@see store()}
     */
    public function __construct(
        private readonly string $type,
        iterable $items,
        ?\Closure $identify = null,
    ) {
        $this->store = new InMemoryStore($items, $identify);
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new ArrayFilterHandler();
        $this->sortHandler = new ArraySortHandler();
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
        /** @var list<object> $items */
        $items = $this->applier->apply(
            $criteria,
            $this->store->all(),
            $this->filterHandler,
            $this->sortHandler,
        );

        $window = $criteria->window;
        if ($window === null) {
            return new CollectionResult($items);
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        return new CollectionResult(
            \array_slice($items, $window->offset, $window->limit),
            \count($items),
        );
    }
}
