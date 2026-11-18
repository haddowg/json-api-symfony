<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortArmInterface;

/**
 * Orders an offset page by a team sort but supplies no keyset expression.
 */
final class OrderOnlyTeamSortArm implements DoctrineSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof TeamSort;
    }

    public function apply(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void
    {
        $query->addOrderBy($alias . '.name', $descending ? 'DESC' : 'ASC');
    }
}
