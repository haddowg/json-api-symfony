<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;

/**
 * Core's {@see ArraySortHandler} with the related types' scopes applied, the sort twin of
 * {@see ScopedArrayFilterHandler}: inside {@see withPaths()}, a sort reaching through
 * relations reads each hop through the rows {@see FilterPaths::rowAdmission()} lets
 * through, so a row the related type's provider hides sorts as `null`.
 *
 * @internal
 *
 * @implements SortHandlerInterface<list<mixed>>
 */
final class ScopedArraySortHandler implements SortHandlerInterface, ReadsFilterPathsInterface
{
    private ArraySortHandler $current;

    public function __construct(private readonly ArraySortHandler $handler)
    {
        $this->current = $handler;
    }

    public function apply(array $sorts, mixed $query): mixed
    {
        return $this->current->apply($sorts, $query);
    }

    public function withPaths(FilterPaths $paths, \Closure $apply): mixed
    {
        $previous = $this->current;
        $this->current = $this->handler->withRowAdmission($paths->rowAdmission())
            ->withRelationReader($paths->relationReader());

        try {
            return $apply();
        } finally {
            $this->current = $previous;
        }
    }
}
