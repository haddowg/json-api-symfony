<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly;

/**
 * A record released on an {@see Imprint}.
 */
final class Record
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?Imprint $imprint = null,
    ) {}
}
