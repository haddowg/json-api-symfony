<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

final class TrLabel
{
    public function __construct(public string $id, public string $name) {}
}
