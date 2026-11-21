<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PriceEntity::class)]
final class PriceResource extends AbstractResource
{
    public static string $type = 'prices';

    public function fields(): array
    {
        return [
            Id::make(),
            Decimal::make('price'),
            Decimal::make('rate')->nullable(),
            Decimal::make('amount')->nullable(),
            Decimal::make('legacyPrice')->nullable(),
            Decimal::make('ratio')->nullable(),
            Integer::make('wholePrice')->storedAs('price')->writeOnly(),
        ];
    }
}
