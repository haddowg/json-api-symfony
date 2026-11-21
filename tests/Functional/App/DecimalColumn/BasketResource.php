<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: BasketEntity::class)]
final class BasketResource extends AbstractResource
{
    public static string $type = 'baskets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('prices', 'prices')
                ->through(BasketLineEntity::class)
                ->fields(Decimal::make('discount')->nullable()->build()),
        ];
    }
}
