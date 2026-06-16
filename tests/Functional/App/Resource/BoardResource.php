<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `boards` resource: the parent of both polymorphic relationships the witness
 * exercises. `pinned` is a polymorphic to-one ({@see MorphTo}) over `notes`/`images`,
 * `items` a polymorphic to-many ({@see MorphToMany}) over the same two types,
 * paginated so the related-collection endpoint witnesses `page` slicing across a
 * mixed-type collection. Both read their related objects off the board model
 * (`storedAs`), and the member serializers (resolved per object) discriminate the
 * `notes`/`images` types.
 */
final class BoardResource extends AbstractResource
{
    public static string $type = 'boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            MorphTo::make('pinned')->types('notes', 'images')->storedAs('pinned'),
            // Countable (bundle ADR 0052): the in-memory provider counts the mixed
            // member set, so ?withCount=items emits the relationship-object meta.total
            // even for a polymorphic to-many (the Doctrine provider throws for it).
            MorphToMany::make('items')->types('notes', 'images')->storedAs('items')->paginate(PagePaginator::make())->countable(),
        ];
    }
}
