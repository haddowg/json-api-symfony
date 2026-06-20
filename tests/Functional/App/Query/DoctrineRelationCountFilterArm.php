<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Query;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterArmInterface;

/**
 * The Doctrine arm for {@see RelationCountAtLeast}: pushes the count predicate down
 * to DQL as `SIZE(<alias>.<relation>) >= :min` — a correlated count, no join, no
 * collection load — on the same primary query, parameter-bound. The conformance
 * witness is {@see InMemoryRelationCountFilterArm}; both select identically.
 */
final class DoctrineRelationCountFilterArm implements DoctrineFilterArmInterface
{
    public function supports(FilterInterface $filter): bool
    {
        return $filter instanceof RelationCountAtLeast;
    }

    public function apply(FilterInterface $filter, QueryBuilder $query, mixed $value, string $alias): void
    {
        \assert($filter instanceof RelationCountAtLeast);
        $min = \is_numeric($value) ? (int) $value : 0;
        $placeholder = 'jsonapi_arm_count_' . \count($query->getParameters());

        $query
            ->andWhere(\sprintf('SIZE(%s.%s) >= :%s', $alias, RelationCount::assertAssociation($filter->relation), $placeholder))
            ->setParameter($placeholder, $min);
    }
}
