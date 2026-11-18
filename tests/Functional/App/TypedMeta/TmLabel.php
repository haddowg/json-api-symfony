<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

final class TmLabel
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
