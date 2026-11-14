<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * A filter handler that reads the declared relations a filter's relationship path
 * walks, for a hop its storage can't follow by name alone, such as a pivot
 * `belongsToMany` the Doctrine handler joins through its association entity. The
 * {@see CriteriaApplier} runs each filter inside {@see withPaths()} when the criteria
 * carries the paths of the type the filters run against.
 *
 * @internal
 */
interface ReadsFilterPathsInterface
{
    /**
     * Runs `$apply` with `$paths` readable, and returns what it returns.
     *
     * @template T
     *
     * @param \Closure(): T $apply
     *
     * @return T
     */
    public function withPaths(FilterPaths $paths, \Closure $apply): mixed;
}
