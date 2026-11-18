<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `stations` type, read-only and served by
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\StationProvider} rather than
 * Doctrine. A station holds none of its plays, so `plays` names the `station` field of
 * `plays` as its foreign key: every read of it, from `/stations/kexp/plays` to
 * `?include=plays` on the collection, is the plays whose `station` is the station's id,
 * cut to its first page per station in one query.
 */
#[AsJsonApiResource(readOnly: true)]
final class StationResource extends AbstractResource
{
    public static string $type = 'stations';

    public function fields(): array
    {
        return [
            Id::make()->example('kexp'),
            Str::make('name')->example('KEXP')->readOnly()->guaranteed(),
            HasMany::make('plays', 'plays', 'station'),
        ];
    }
}
