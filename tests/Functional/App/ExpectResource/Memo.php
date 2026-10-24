<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

/**
 * A model whose resource declares no entity class.
 */
final class Memo
{
    public function __construct(
        public ?int $id = null,
        public string $body = '',
    ) {}
}
