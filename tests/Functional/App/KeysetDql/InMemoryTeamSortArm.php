<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * Orders an in-memory page by a team sort's keyset value.
 */
final class InMemoryTeamSortArm implements ArraySortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof TeamSort;
    }

    public function value(SortInterface $sort, mixed $row): mixed
    {
        \assert($sort instanceof TeamSort);

        return $sort->keysetValue($row);
    }
}
