<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Tracks again, whose playlists declare a filter family resolving `filter[note.text]` to the
 * deprecated path `pivot.note.text`, past the pivot row to the note no type serves. The
 * warm-up can't see a family's paths, so the request answers `500`.
 */
#[AsJsonApiResource(entity: TpTrackEntity::class, readOnly: true)]
final class TpFamilyTrackResource extends AbstractResource
{
    public static string $type = 'tpFamilyTracks';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            BelongsToMany::make('playlists', 'tpPlaylists')
                ->through(TpEntryEntity::class)
                ->fields(Integer::make('position'))
                ->withFilters(FilterFamily::make('note', static fn(string $suffix, JsonApiRequestInterface $request): FilterInterface => WhereThrough::make('note.' . $suffix, 'pivot.note.' . $suffix)->build())),
        ];
    }
}
