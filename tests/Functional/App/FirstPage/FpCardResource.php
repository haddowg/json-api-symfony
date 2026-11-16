<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fp-cards`, whose `comments` are paginated one a page, so a nested include of them
 * is cut too.
 */
abstract class FpCardResource extends AbstractResource
{
    public static string $type = 'fp-cards';

    public function getType(mixed $object): string
    {
        return $object instanceof FpCard ? static::$type : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            DateTime::make('updatedAt')->sortable(),
            BelongsTo::make('board', 'fp-boards')->nullable(),
            HasMany::make('comments', 'fp-comments')->paginate(PagePaginator::make()->withDefaultPerPage(1)),
        ];
    }
}
