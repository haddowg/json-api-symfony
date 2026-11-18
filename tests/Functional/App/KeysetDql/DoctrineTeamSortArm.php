<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineKeysetSortArmInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\KeysetExpression;

/**
 * The top score is a subquery the cursor reads back from the query, since the entity
 * can't compute it. The tier is a bare `CASE` and the points a bare `COALESCE`.
 */
final class DoctrineTeamSortArm implements DoctrineKeysetSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof TeamSort;
    }

    public function apply(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void
    {
        $name = 'team_sort_' . $sort->key();
        $query->addSelect(\sprintf('%s AS HIDDEN %s', $this->keysetExpression($sort, $query, $alias)->dql, $name))
            ->addOrderBy($name, $descending ? 'DESC' : 'ASC');
    }

    public function keysetExpression(SortInterface $sort, QueryBuilder $query, string $alias): KeysetExpression
    {
        return match ($sort->key()) {
            'topScore' => KeysetExpression::repeatable(
                static fn(string $suffix): string => \sprintf('(SELECT MAX(top_member%1$s.score) FROM %2$s top_member%1$s WHERE top_member%1$s.team = %3$s)', $suffix, MemberEntity::class, $alias),
                Types::INTEGER,
                valueFromQuery: true,
            ),
            // DQL 2 has no NULL literal in a CASE, so NULLIF(1, 1) stands in for one.
            'tier' => new KeysetExpression(\sprintf('CASE WHEN %1$s.base >= 10 THEN 2 WHEN %1$s.base < 10 THEN 1 ELSE NULLIF(1, 1) END', $alias), Types::INTEGER),
            default => new KeysetExpression(\sprintf('COALESCE(%1$s.bonus, %1$s.base)', $alias), Types::INTEGER),
        };
    }
}
