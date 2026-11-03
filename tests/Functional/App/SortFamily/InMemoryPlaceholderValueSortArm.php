<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

final class InMemoryPlaceholderValueSortArm implements ArraySortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof PlaceholderValueSort;
    }

    public function value(SortInterface $sort, mixed $row): mixed
    {
        \assert($sort instanceof PlaceholderValueSort);

        return $sort->keysetValue($row);
    }
}
