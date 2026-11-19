<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Tracks on playlists through pivot rows that hold who added each track and who audited
 * it. The playlists' filters and sorts reach those rows with a leading `pivot`, and the
 * deprecated `pivot.`-prefixed traversals sit beside their migrated forms.
 */
#[AsJsonApiResource(entity: TpTrackEntity::class, readOnly: true)]
final class TpTrackResource extends AbstractResource
{
    public static string $type = 'tpTracks';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            BelongsTo::make('album', 'tpAlbums')->nullable(),
            BelongsToMany::make('playlists', 'tpPlaylists')
                ->through(TpEntryEntity::class)
                ->fields(Integer::make('position'), DateTime::make('addedAt')->readOnly())
                ->withFilters(
                    Where::make('addedBy', 'name')->through('pivot.addedBy'),
                    WhereIdIn::make('adder')->through('pivot.addedBy')->acceptsList(),
                    DateRange::make('addedAt', 'addedAt')->through('pivot'),
                    Where::make('position', 'position')->through('pivot')->integer(),
                    Where::make('auditedBy', 'name')->through('pivot.auditor'),
                    WhereDoesntHave::make('unaudited', 'auditor')->through('pivot'),
                    WhereThrough::make('legacyAddedBy', 'pivot.addedBy.name'),
                    WhereThroughIn::make('legacyAdder', 'pivot.addedBy'),
                    WhereDoesntHave::make('legacyUnaudited', 'pivot.auditor'),
                )
                ->withSorts(
                    SortByField::make('added', 'addedAt')->through('pivot'),
                    SortByField::make('adder', 'name')->through('pivot.addedBy'),
                ),
            // The same pivot rows on cursor pages, newest addition first by default.
            BelongsToMany::make('cursorPlaylists', 'tpPlaylists')
                ->through(TpEntryEntity::class)
                ->fields(Integer::make('position'), DateTime::make('addedAt')->readOnly())
                ->withSorts(
                    SortByField::make('added', 'addedAt')->through('pivot'),
                    SortByField::make('adder', 'name')->through('pivot.addedBy'),
                )
                ->defaultSort(new SortDirective(SortByField::make('added', 'addedAt')->through('pivot'), descending: true))
                ->paginate(CursorPaginator::make()->withDefaultSize(1)),
        ];
    }
}
