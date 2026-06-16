<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory far (related) `tracks` POJO for the pivot boundary witness. The
 * in-memory provider has no association entity to read pivot columns from, so this
 * carries no pivot value — the boundary the {@see \haddowg\JsonApiBundle\Tests\Functional\InMemoryPivotBoundaryTest}
 * asserts (a pivot key is unrecognised → 400; no pivot meta renders).
 */
final class PivotTrack
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
