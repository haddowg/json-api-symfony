<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory parent `playlists` POJO for the pivot boundary witness: it holds
 * its `tracks` directly (no association entity — in-memory cannot model pivot
 * columns), so the `tracks` related collection reads straight off this object and a
 * pivot `?filter`/`?sort` key stays unrecognised (400).
 */
final class PivotPlaylist
{
    /**
     * @param list<PivotTrack> $tracks
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $tracks,
    ) {}
}
