<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogConsumer;

/**
 * One operation of the API, as the {@see CapabilityProvider} lists it.
 */
final class Capability
{
    public function __construct(
        public string $id = '',
        public string $method = '',
    ) {}
}
