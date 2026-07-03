<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortHandler;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\RelationCountArmDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * When a custom filter/sort reaches the Doctrine handler with no registered arm to run
 * it, the resulting 500 ({@see UnsupportedFilter}/{@see UnsupportedSort}, raised by core)
 * carries the Doctrine handler's own remediation hint — naming the arm seam that would
 * handle it. Core supplies only the generic message; the Doctrine provider, which alone
 * knows its extension seam, appends the guidance (via the exceptions' `$hint` slot).
 */
final class DoctrineUnsupportedArmHintTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return RelationCountArmDoctrineTestKernel::class;
    }

    #[Test]
    public function anUnsupportedCustomFilterNamesTheDoctrineArmSeam(): void
    {
        $filter = new class implements FilterInterface {
            public function key(): string
            {
                return 'bespoke';
            }

            public function constraints(): array
            {
                return [];
            }
        };

        try {
            (new DoctrineFilterHandler())->apply($filter, $this->queryBuilder(), 'x');
            self::fail('Expected UnsupportedFilter.');
        } catch (UnsupportedFilter $e) {
            self::assertStringContainsString('DoctrineFilterArmInterface', $e->getMessage());
            self::assertStringContainsString('arm seam', $e->getMessage());
        }
    }

    #[Test]
    public function anUnsupportedCustomSortNamesTheDoctrineArmSeam(): void
    {
        $sort = new class implements SortInterface {
            public function key(): string
            {
                return 'bespoke';
            }
        };

        try {
            (new DoctrineSortHandler())->apply([new SortDirective($sort, false)], $this->queryBuilder());
            self::fail('Expected UnsupportedSort.');
        } catch (UnsupportedSort $e) {
            self::assertStringContainsString('DoctrineSortArmInterface', $e->getMessage());
            self::assertStringContainsString('arm seam', $e->getMessage());
        }
    }

    private function queryBuilder(): QueryBuilder
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em->createQueryBuilder()->select('a')->from(ArticleEntity::class, 'a');
    }
}
