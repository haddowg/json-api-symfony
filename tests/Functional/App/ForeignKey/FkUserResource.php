<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * `fk-users`, served by {@see FkUserProvider}, whose relations each read through a
 * foreign key on the related type: `items` two a page and countable, `cursorItems` the
 * same items two a cursor page, `featuredItems` the same items rendered `withData()`,
 * `notes`, whose type hides archived notes by a filter default, `secrets`, whose type a
 * Doctrine extension scopes, and the `profile` to-one.
 */
#[AsJsonApiResource(readOnly: true)]
final class FkUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('items', 'fk-items', 'owner')->paginate(PagePaginator::make()->withDefaultPerPage(2))->countable(),
            HasMany::make('cursorItems', 'fk-items', 'owner')->paginate(CursorPaginator::make()->withDefaultSize(2)),
            HasMany::make('featuredItems', 'fk-items', 'owner')->paginate(PagePaginator::make()->withDefaultPerPage(2))->withData(),
            HasMany::make('notes', 'fk-notes', foreignKey: 'owner')->withoutPagination()->countable(),
            HasMany::make('secrets', 'fk-secrets', 'owner')->withoutPagination()->countable(),
            HasOne::make('profile', 'fk-profiles', 'owner'),
        ];
    }
}
