<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;

/**
 * The plain-JSON body the album import accepts: a list of albums to create, emitted
 * once as the `AlbumImportRows` schema component.
 */
final class AlbumImportRows implements DescribesRawBody
{
    public static function jsonSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'releasedOn' => ['type' => 'string', 'format' => 'date'],
                ],
                'required' => ['title'],
            ],
        ];
    }
}
