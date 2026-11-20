<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Setlists over the same in-memory playlists, with a filter default whose path has
 * `pivot` after a relation. The in-memory provider holds no pivot rows, so the default
 * answers `400` on every read of the collection.
 */
#[AsJsonApiResource(readOnly: true)]
final class PivotSetlistResource extends AbstractResource
{
    public static string $type = 'setlists';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name'),
            BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->build()),
        ];
    }

    public function filters(): array
    {
        return [Where::make('firstSlot', 'position')->through('tracks.pivot')->default('1')];
    }
}
