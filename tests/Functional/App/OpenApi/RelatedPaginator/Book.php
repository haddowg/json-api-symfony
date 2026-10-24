<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\RelatedPaginator;

final class Book
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
