<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

/**
 * A plain object with no column mapping: `amount` takes the float a `Decimal`
 * hydrates, and `label` a string the field converts to itself.
 */
final class PlainPrice
{
    public function __construct(
        public ?int $id = null,
        public ?float $amount = null,
        public ?string $label = null,
    ) {}
}
