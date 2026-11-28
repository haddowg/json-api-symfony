<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory far (related) `tracks` POJO for the pivot boundary witness. Its pivot
 * values live on the playlist's pivot rows, never on the track, as the
 * {@see \haddowg\JsonApiBundle\Tests\Functional\InMemoryPivotBoundaryTest} asserts.
 */
final class PivotTrack
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
