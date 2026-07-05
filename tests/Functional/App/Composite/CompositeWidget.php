<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

/**
 * A plain model for the composite-attribute witness: `address` backs an {@see \haddowg\JsonApi\Resource\Field\Obj}
 * (a typed object in one value) and `block` a {@see \haddowg\JsonApi\Resource\Field\OneOf}
 * (a discriminated union) — both stored as a single array, the shape a `json` column
 * round-trips.
 */
final class CompositeWidget
{
    /**
     * @param array<string, mixed>|null $address
     * @param array<string, mixed>|null $block
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $address = null,
        public ?array $block = null,
    ) {}
}
