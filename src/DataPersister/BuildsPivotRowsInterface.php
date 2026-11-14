<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * A persister that creates the association rows of a pivot `belongsToMany`, and lets
 * the handler supply how each new row is built. The handler wraps a write in
 * {@see withPivotRowFactory()} when the type declares
 * {@see \haddowg\JsonApiBundle\Hook\InstantiatesPivotInterface}.
 */
interface BuildsPivotRowsInterface
{
    /**
     * Runs `$write` with `$factory` building every pivot row it creates, and returns
     * what `$write` returns. The factory receives the parent, the related entity and
     * the member's `meta.pivot`, and returns the new row, or null to build it the
     * persister's own way.
     *
     * @template T
     *
     * @param \Closure(object, object, array<string, mixed>): ?object $factory
     * @param \Closure(): T                                          $write
     *
     * @return T
     */
    public function withPivotRowFactory(\Closure $factory, \Closure $write): mixed;
}
