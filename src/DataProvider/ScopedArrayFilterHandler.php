<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;

/**
 * Core's {@see ArrayFilterHandler} with the related types' scopes applied: inside
 * {@see withPaths()}, each relation a filter walks narrows the rows it reaches to those
 * {@see FilterPaths::admit()} lets through, so a row the related type's provider hides
 * is never matched, and a `WhereMorph` matches only the targets
 * {@see FilterPaths::admitMorphTargets()} lets through.
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

    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        return $this->current->apply($filter, $query, $value);
    }

    public function withPaths(FilterPaths $paths, \Closure $apply): mixed
    {
        $previous = $this->current;
        $this->current = $this->handler->withRowAdmission(static function (string $path, array $reached) use ($paths): array {
            $relation = $paths->relationAt($path);
            if ($relation === null) {
                return $reached;
            }

            if ($paths->refuses($relation)) {
                return [];
            }

            // Only an object row has a provider to ask; an array row stays as it is.
            $objects = \array_values(\array_filter($reached, \is_object(...)));
            if ($objects === []) {
                return $reached;
            }
            $admitted = [];
            foreach ($paths->admit($relation, $objects) as $row) {
                $admitted[\spl_object_id($row)] = true;
            }

            return \array_values(\array_filter($reached, static fn(mixed $row): bool => !\is_object($row) || isset($admitted[\spl_object_id($row)])));
        })->withMorphAdmission(static fn(RelationInterface $relation, string $type, array $keys): array => $paths->admitMorphTargets($relation, $type, $keys));

        try {
            return $apply();
        } finally {
            $this->current = $previous;
        }
    }
}
