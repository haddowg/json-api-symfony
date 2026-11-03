<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineKeysetSortArmInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\KeysetExpression;

/**
 * Joins the placeholder's row of the value table and orders by its text cast to an
 * integer, on offset and cursor pages alike.
 */
final class DoctrinePlaceholderValueSortArm implements DoctrineKeysetSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof PlaceholderValueSort;
    }

    public function apply(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void
    {
        $query->addOrderBy($this->keysetExpression($sort, $query, $alias)->dql, $descending ? 'DESC' : 'ASC');
    }

    public function keysetExpression(SortInterface $sort, QueryBuilder $query, string $alias): KeysetExpression
    {
        \assert($sort instanceof PlaceholderValueSort);
        // The resource's suffix pattern keeps the placeholder a plain identifier.
        $join = 'placeholder_' . $sort->placeholder;

        $query
            ->leftJoin(CampaignValueEntity::class, $join, 'WITH', \sprintf('%1$s.campaign = %2$s AND %1$s.name = :%1$s_name', $join, $alias))
            ->setParameter($join . '_name', $sort->placeholder);

        return new KeysetExpression(\sprintf('CAST_INTEGER(%s.value)', $join), Types::INTEGER);
    }
}
