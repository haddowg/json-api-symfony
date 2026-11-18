<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Model;

/**
 * A radio station, served from a fixed list by
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\StationProvider}. It holds
 * none of its plays: they live in the database and point back at it.
 */
final class Station
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
