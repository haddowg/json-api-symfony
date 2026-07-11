<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Sparse;

/**
 * A plain domain model for the sparse-by-default witness: a cheap `name` plus an
 * `expensiveScore` the resource marks {@see \haddowg\JsonApi\Resource\Field\AbstractFieldBuilder::sparseByDefault()},
 * so it renders only when the client names it in `fields[sparseWidgets]`.
 */
final class SparseWidget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public int $expensiveScore = 0,
    ) {}
}
