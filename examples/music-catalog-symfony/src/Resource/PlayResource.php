<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Play;

/**
 * The `plays` type: a track played on a station. `station` holds the id of the
 * provider-served station that played it, which {@see StationResource}'s `plays` reads
 * through as its foreign key.
 */
#[AsJsonApiResource(entity: Play::class, readOnly: true)]
final class PlayResource extends AbstractResource
{
    public static string $type = 'plays';

    public function fields(): array
    {
        return [
            Id::make()->example('1'),
            Str::make('station')->example('kexp')->readOnly()->guaranteed(),
            DateTime::make('playedAt')->example('2026-01-01T08:00:00+00:00')->readOnly()->sortable()->guaranteed(),
            BelongsTo::make('track', 'tracks')->nullable(),
        ];
    }
}
