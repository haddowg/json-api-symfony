<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;

/**
 * An in-memory read provider: a test double and conformance witness. It holds a
 * per-type map of domain objects keyed by id and answers `fetchOne()` /
 * `fetchCollection()` straight from it, so a slice runs with zero database.
 *
 * It lives in `src/` (not `tests/`) so it is reusable as a documented worked
 * example, mirroring how core ships its `InMemory\Array{Filter,Sort}Handler`.
 * One instance answers for a single `$type`.
 */
final class InMemoryDataProvider implements DataProviderInterface
{
    /**
     * @var array<string, object>
     */
    private readonly array $itemsById;

    /**
     * @param iterable<int|string, object> $items objects keyed by id
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
    }

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->itemsById[$id] ?? null;
    }

    public function fetchCollection(string $type, QueryParameters $queryParameters): iterable
    {
        return \array_values($this->itemsById);
    }
}
