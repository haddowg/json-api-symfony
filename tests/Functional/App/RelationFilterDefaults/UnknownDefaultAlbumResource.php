<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use haddowg\JsonApi\Resource\Field\HasMany;

/**
 * Drops the default of a key the tracks type doesn't default, which fails warm-up.
 */
final class UnknownDefaultAlbumResource extends FdAlbumResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            HasMany::make('titledTracks', 'tracks')
                ->storedAs('tracks')
                ->withoutFilterDefaults('title'),
        ];
    }
}
