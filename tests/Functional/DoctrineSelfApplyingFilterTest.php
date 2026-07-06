<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\AppliesToQueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\RelationCountArmDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * A filter that implements {@see AppliesToQueryBuilder} carries its own Doctrine query
 * fragment, so the handler runs it with **no** {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterArmInterface}
 * registered — the self-applying twin of the arm seam, consulted before the arm registry.
 */
final class DoctrineSelfApplyingFilterTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return RelationCountArmDoctrineTestKernel::class;
    }

    #[Test]
    public function aSelfApplyingFilterAddsItsOwnPredicateWithNoArmRegistered(): void
    {
        $filter = new class implements AppliesToQueryBuilder {
            public function key(): string
            {
                return 'titled';
            }

            public function constraints(): array
            {
                return [];
            }

            public function applyToQueryBuilder(QueryBuilder $query, mixed $value, string $alias): void
            {
                // Bind the value as a parameter with a collision-free name (off the running
                // parameter count), clear of the reserved jsonapi_ prefix — never interpolated.
                $name = 'self_' . \count($query->getParameters());
                $query->andWhere(\sprintf('%s.title = :%s', $alias, $name))->setParameter($name, $value);
            }
        };

        self::assertInstanceOf(FilterInterface::class, $filter);

        $qb = $this->queryBuilder();
        (new DoctrineFilterHandler())->apply($filter, $qb, 'JSON:API in PHP');

        self::assertStringContainsString('a.title = :self_0', $qb->getDQL());
        self::assertSame('JSON:API in PHP', $qb->getParameter('self_0')?->getValue());
    }

    private function queryBuilder(): QueryBuilder
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em->createQueryBuilder()->select('a')->from(ArticleEntity::class, 'a');
    }
}
