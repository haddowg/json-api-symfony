<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionCreated;

/**
 * An in-memory order.
 */
final class Order
{
    public function __construct(
        public string $id,
        public string $reference,
    ) {}
}
