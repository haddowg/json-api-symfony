<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Albums filtered through their tracks' playlist pivot rows, a `pivot` in the middle of
 * the path.
 */
#[AsJsonApiResource(entity: TpAlbumEntity::class, readOnly: true)]
final class TpAlbumResource extends AbstractResource
{
    public static string $type = 'tpAlbums';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            HasMany::make('tracks', 'tpTracks'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('adder', 'name')->through('tracks.playlists.pivot.addedBy'),
            DateRange::make('addedAt', 'addedAt')->through('tracks.playlists.pivot'),
            Where::make('auditor', 'name')->through('tracks.playlists.pivot.auditor'),
        ];
    }
}
