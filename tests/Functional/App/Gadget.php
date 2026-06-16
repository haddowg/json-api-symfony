<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `gadget` model for the custom serializer/hydrator witness (ADR 0023):
 * its reads run through {@see GadgetSerializer} and its writes through
 * {@see GadgetHydrator}, not the resource's field DSL.
 */
final class Gadget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
