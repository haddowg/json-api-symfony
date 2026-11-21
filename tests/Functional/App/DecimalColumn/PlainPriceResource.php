<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;

final class PlainPriceResource extends AbstractResource
{
    public static string $type = 'plainPrices';

    public function fields(): array
    {
        return [
            Id::make(),
            Decimal::make('amount')->nullable(),
            Decimal::make('label')->nullable()->deserializeUsing(
                static fn(mixed $value): mixed => \is_int($value) || \is_float($value) ? \number_format($value, 2, '.', '') : $value,
            ),
        ];
    }
}
