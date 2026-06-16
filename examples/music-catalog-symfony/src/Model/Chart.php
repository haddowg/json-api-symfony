<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Model;

/**
 * A read-only chart — the store-backed twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/Chart.php Chart}.
 *
 * It is the 8th domain but has **no Doctrine entity and no Resource**: it is served
 * by a standalone hand-written {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\ChartSerializer}
 * (registered by `#[AsJsonApiSerializer]`) plus a small custom
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\ChartProvider} that
 * returns a fixed list — the capability-composition witness for a resource-less,
 * read-only, serialize-plus-fetch type.
 */
final class Chart
{
    /**
     * @param list<array{rank: int, trackId: string, plays: int}> $entries
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $period = '',
        public array $entries = [],
    ) {}
}
