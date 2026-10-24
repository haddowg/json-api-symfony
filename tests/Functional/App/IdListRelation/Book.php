<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdListRelation;

final class Book
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
