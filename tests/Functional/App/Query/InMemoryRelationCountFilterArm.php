<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Query;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface;

/**
 * The in-memory arm for {@see RelationCountAtLeast}: keeps a row whose related
 * collection (read off the object via core's {@see Accessor}) has at least the
 * requested size — the conformance witness for the Doctrine `SIZE(...)` push-down.
 */
final class InMemoryRelationCountFilterArm implements ArrayFilterArmInterface
{
    public function supports(FilterInterface $filter): bool
    {
        return $filter instanceof RelationCountAtLeast;
    }

    public function predicate(FilterInterface $filter, mixed $value): \Closure
    {
        \assert($filter instanceof RelationCountAtLeast);
        $relation = $filter->relation;
        $min = \is_numeric($value) ? (int) $value : 0;

        return static fn(mixed $row): bool => RelationCount::of(Accessor::get($row, $relation)) >= $min;
    }
}
