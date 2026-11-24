<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Through\FiltersThrough;

/**
 * Core's {@see ArrayFilterHandler} with the related types' scopes applied: inside
 * {@see withPaths()}, each relation a filter walks narrows the rows it reaches to those
 * {@see FilterPaths::admit()} lets through, so a row the related type's provider hides
 * is never matched, and a `WhereMorph` matches only the targets
 * {@see FilterPaths::admitMorphTargets()} lets through. A path reaching a pivot row is
 * unrecognised, as a pivot column filter is here, since no in-memory row holds one.
 *
 * @internal
 *
 * @implements FilterHandlerInterface<list<mixed>>
 */
final class ScopedArrayFilterHandler implements FilterHandlerInterface, ReadsFilterPathsInterface
{
    private ArrayFilterHandler $current;

    public function __construct(private readonly ArrayFilterHandler $handler)
    {
        $this->current = $handler;
    }

    /**
     * @throws FilterParamUnrecognized when the filter's path reaches a pivot row, which no in-memory row holds
     */
    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        if ($filter instanceof FiltersThrough && $filter->resolvedThrough()?->reachesPivot() === true) {
            throw new FilterParamUnrecognized($filter->key());
        }

        return $this->current->apply($filter, $query, $value);
    }

    public function withPaths(FilterPaths $paths, \Closure $apply): mixed
    {
        $previous = $this->current;
        $this->current = $this->handler->withRowAdmission($paths->rowAdmission())
            ->withRelationReader($paths->relationReader())
            ->withMorphAdmission(static fn(RelationInterface $relation, string $type, array $keys): array => $paths->admitMorphTargets($relation, $type, $keys));

        try {
            return $apply();
        } finally {
            $this->current = $previous;
        }
    }
}
