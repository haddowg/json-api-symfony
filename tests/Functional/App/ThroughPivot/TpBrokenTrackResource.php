<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Tracks again, on their playlists over the association entity with no stored pivot fields
 * declared: core accepts a path past that pivot row, which the Doctrine provider reads no pivot
 * rows of, so the warm-up refuses it.
 */
#[AsJsonApiResource(entity: TpTrackEntity::class, readOnly: true)]
final class TpBrokenTrackResource extends AbstractResource
{
    public static string $type = 'tpBrokenTracks';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            BelongsToMany::make('barePlaylists', 'tpPlaylists')->through(TpEntryEntity::class),
        ];
    }

    public function filters(): array
    {
        return [Where::make('x', 'name')->through('barePlaylists.pivot.addedBy')];
    }
}
