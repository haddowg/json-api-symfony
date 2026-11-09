<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereIn;

/**
 * Boards of items. The `items` relation adds an exact `any` filter, which the items'
 * own `any` family leaves alone because a family never answers its bare prefix.
 */
abstract class BaseBoardResource extends AbstractResource
{
    public static string $type = 'ffBoards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('items', 'ffItems')
                ->withFilters(WhereIn::make('any', 'color')->acceptsList()),
        ];
    }
}
