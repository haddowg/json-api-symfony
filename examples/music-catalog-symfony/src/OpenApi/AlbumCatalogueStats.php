<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;

/**
 * The plain-JSON form of the album export: counts by publication state, emitted once
 * as the `AlbumCatalogueStats` schema component.
 */
final class AlbumCatalogueStats implements DescribesRawBody
{
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'published' => ['type' => 'integer', 'minimum' => 0],
                'unpublished' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['published', 'unpublished'],
        ];
    }
}
