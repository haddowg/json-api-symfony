<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * A {@see DoctrineSortArmInterface} that can also order a cursor (keyset) page by its
 * custom sort. The sort must implement core's
 * {@see \haddowg\JsonApi\Resource\Sort\KeysetSortInterface}, which reads the value a
 * boundary row writes into the cursor token; this arm supplies the matching DQL
 * expression, and {@see DoctrineKeyset} builds the forced NULL=largest `ORDER BY` and
 * the keyset `WHERE` from it exactly as it does for a column.
 *
 * Registered like any sort arm (autoconfigured through the parent interface). On a
 * cursor page {@see keysetExpression()} runs instead of {@see apply()}; a custom sort
 * level whose arm does not implement this interface is a `500`
 * {@see \haddowg\JsonApi\Resource\Sort\UnsupportedSort}.
 *
 * ```php
 * public function keysetExpression(SortInterface $sort, QueryBuilder $query, string $alias): KeysetExpression
 * {
 *     \assert($sort instanceof PlaceholderValueSort);
 *     $join = 'value_' . $sort->placeholder; // a validated identifier
 *     $query->leftJoin(PlaceholderValue::class, $join, 'WITH', \sprintf('%1$s.campaign = %2$s AND %1$s.name = :%1$s_name', $join, $alias))
 *         ->setParameter($join . '_name', $sort->placeholder);
 *
 *     // CAST_INTEGER is a DQL function the application registers.
 *     return new KeysetExpression(\sprintf('CAST_INTEGER(%s.value)', $join), Types::INTEGER);
 * }
 * ```
 */
interface DoctrineKeysetSortArmInterface extends DoctrineSortArmInterface
{
    /**
     * The expression `$sort` orders a cursor page by on `$alias`, adding to `$query`
     * any join or parameter it needs. Called once per query for each level the sort
     * orders, and only when {@see supports()} returned `true`.
     */
    public function keysetExpression(SortInterface $sort, QueryBuilder $query, string $alias): KeysetExpression;
}
