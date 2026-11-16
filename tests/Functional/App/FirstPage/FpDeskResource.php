<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * The `fp-desks` singleton at `/desk`: its `cards` are cursor paginated two at a time,
 * most recently updated first.
 */
abstract class FpDeskResource extends AbstractResource
{
    public static string $type = 'fp-desks';

    public static string $uriType = 'desk';

    public static bool $singleton = true;

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        return '1';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('cards', 'fp-cards')
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->defaultSort(new SortDirective(SortByField::make('updatedAt'), descending: true)),
        ];
    }
}
