<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * Four views of one album's tracks. `tracks` keeps both of the tracks type's defaults,
 * `explicitTracks` drops the `explicit` one, `allTracks` drops both, and `cleanTracks`
 * drops both but declares its own `explicit` default.
 */
class FdAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            HasMany::make('tracks', 'tracks')->withData()->countable(),
            HasMany::make('explicitTracks', 'tracks')
                ->storedAs('tracks')
                ->withData()
                ->countable()
                ->withoutFilterDefaults('explicit'),
            HasMany::make('allTracks', 'tracks')
                ->storedAs('tracks')
                ->withData()
                ->countable()
                ->withoutFilterDefaults(),
            HasMany::make('cleanTracks', 'tracks')
                ->storedAs('tracks')
                ->withData()
                ->countable()
                ->withoutFilterDefaults()
                ->withFilters(Where::make('explicit')->asBoolean()->default(false)),
        ];
    }
}
