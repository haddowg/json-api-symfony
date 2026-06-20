<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Query;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortArmInterface;

/**
 * The Doctrine arm for {@see OrderByRelationCount}: appends `ORDER BY` the related
 * collection's size. DQL cannot place `SIZE(...)` directly in `ORDER BY`, so it is
 * selected as a `HIDDEN` result variable (excluded from the hydrated result) and the
 * order references that variable — `addOrderBy`, so it composes with any preceding
 * directive (e.g. an `id` tie-breaker). Conformance witness:
 * {@see InMemoryRelationCountSortArm}.
 */
final class DoctrineRelationCountSortArm implements DoctrineSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof OrderByRelationCount;
    }

    public function apply(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void
    {
        \assert($sort instanceof OrderByRelationCount);
        $relation = RelationCount::assertAssociation($sort->relation);
        // A DISTINCT HIDDEN result-variable per relation, so two count sorts over
        // different to-manys in one request (`sort=commentCount,editorCount`) do not
        // collide on the alias. The relation is a validated identifier.
        $variable = 'jsonapi_arm_count_' . $relation;

        $query
            ->addSelect(\sprintf('SIZE(%s.%s) AS HIDDEN %s', $alias, $relation, $variable))
            ->addOrderBy($variable, $descending ? 'DESC' : 'ASC');
    }
}
