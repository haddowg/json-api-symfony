<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Query;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * The in-memory arm for {@see OrderByRelationCount}: contributes the related
 * collection's size as the per-row sort key, so a custom count sort weaves into the
 * handler's lexicographic cascade — the conformance witness for the Doctrine
 * `ORDER BY SIZE(...)` push-down.
 */
final class InMemoryRelationCountSortArm implements ArraySortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof OrderByRelationCount;
    }

    public function value(SortInterface $sort, mixed $row): mixed
    {
        \assert($sort instanceof OrderByRelationCount);

        return RelationCount::of(Accessor::get($row, $sort->relation));
    }
}
