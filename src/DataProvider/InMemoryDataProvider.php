<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;

/**
 * An in-memory read provider: a test double and conformance witness. It holds a
 * per-type map of domain objects keyed by id and answers `fetchOne()` /
 * `fetchCollection()` straight from it, so a slice runs with zero database.
 *
 * Collections run the same {@see CriteriaApplier} matching as the Doctrine
 * provider, executed through core's reference in-memory handlers
 * ({@see ArrayFilterHandler} / {@see ArraySortHandler}) with an `array_slice`
 * for the pagination window — so a spec test failing on one provider but not
 * the other localizes the bug to that provider's *execution*.
 *
 * It lives in `src/` (not `tests/`) so it is reusable as a documented worked
 * example, mirroring how core ships its `InMemory\Array{Filter,Sort}Handler`.
 * One instance answers for a single `$type`, so `TEntity` is inferred from the
 * seed items at construction.
 *
 * @template TEntity of object
 *
 * @implements DataProviderInterface<TEntity>
 */
final class InMemoryDataProvider implements DataProviderInterface
{
    /**
     * @var array<string, TEntity>
     */
    private readonly array $itemsById;

    private readonly CriteriaApplier $applier;

    private readonly ArrayFilterHandler $filterHandler;

    private readonly ArraySortHandler $sortHandler;

    /**
     * @param iterable<int|string, TEntity> $items objects keyed by id
     */
    public function __construct(
        private readonly string $type,
        iterable $items,
    ) {
        $byId = [];
        foreach ($items as $id => $item) {
            $byId[(string) $id] = $item;
        }

        $this->itemsById = $byId;
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new ArrayFilterHandler();
        $this->sortHandler = new ArraySortHandler();
    }

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->itemsById[$id] ?? null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        /** @var list<TEntity> $items */
        $items = $this->applier->apply(
            $criteria,
            \array_values($this->itemsById),
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
