<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The far (related) `tracks` type of the pivot fixture: a track with a `title`
 * attribute plus a declared `filter[title]` and `sort=title` so a pivot fetch can
 * be proven to COMPOSE a related-entity filter (on `tracks`) with a pivot filter
 * (on the association entity) in one correctly-paginated query, and so a pivot key
 * on the primary `/tracks` collection is unrecognised (400) — pivot is scoped to
 * the related endpoint only.
 */
#[AsJsonApiResource(entity: TrackEntity::class)]
final class DoctrineTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('title', 'title', 'like'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('title', 'title'),
        ];
    }
}
