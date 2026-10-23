<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The Doctrine advertiser's pivot relations over {@see ScopingPlacement}: `placedAgencies`
 * (paginated) and `listedPlacedAgencies`, which always emits its linkage data.
 */
trait DeclaresScopingPivotRelations
{
    public function fields(): array
    {
        $placed = static fn(mixed $model): array => $model instanceof ScopingAdvertiser ? $model->getPlacedAgencies() : [];

        return [
            ...parent::fields(),
            BelongsToMany::make('placedAgencies', static::PREFIX . 'agencies')
                ->through(ScopingPlacement::class)
                ->fields(Str::make('role')->build())
                ->extractUsing($placed)
                ->paginate(PagePaginator::make()),
            BelongsToMany::make('listedPlacedAgencies', static::PREFIX . 'agencies')
                ->through(ScopingPlacement::class)
                ->fields(Str::make('role')->build())
                ->extractUsing($placed)
                ->withData(),
        ];
    }
}
