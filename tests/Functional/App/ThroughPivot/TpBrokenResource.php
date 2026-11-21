<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A type declaring one pivot path the warm-up refuses, chosen by {@see $declaration}.
 */
#[AsJsonApiResource(entity: TpAlbumEntity::class, readOnly: true)]
final class TpBrokenResource extends AbstractResource
{
    public static string $type = 'tpBroken';

    public static string $declaration = '';

    public function fields(): array
    {
        $tracks = HasMany::make('tracks', 'tpTracks');

        return [
            Id::make()->numeric(),
            self::$declaration === 'plain-relation-pivot' ? $tracks->withFilters(Where::make('x', 'title')->through('pivot')) : $tracks,
            BelongsToMany::make('plainTracks', 'tpTracks')->storedAs('tracks'),
            BelongsToMany::make('entryTracks', 'tpTracks')->through(TpEntryEntity::class)->fields(Integer::make('position')),
        ];
    }

    public function filters(): array
    {
        return match (self::$declaration) {
            'leading-pivot-on-collection' => [Where::make('x', 'position')->through('pivot')],
            'pivot-after-plain-to-many' => [Where::make('x', 'title')->through('plainTracks.pivot')],
            'unknown-pivot-row-relation' => [Where::make('x', 'name')->through('tracks.playlists.pivot.owner')],
            default => [],
        };
    }

    public function sorts(): array
    {
        return self::$declaration === 'mid-path-pivot-sort' ? [SortByField::make('x', 'position')->through('entryTracks.pivot')] : [];
    }
}
