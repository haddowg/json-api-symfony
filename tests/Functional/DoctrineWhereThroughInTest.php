<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\LeadEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\ThroughInDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\ThroughInFixtures;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see WhereThroughInConformanceTestCase} against the Doctrine provider, where each
 * traversal is one correlated EXISTS whose leaf is an IN over the requested list.
 */
final class DoctrineWhereThroughInTest extends WhereThroughInConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ThroughInDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ThroughInFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function appliedDirectly(WhereThroughIn $filter, mixed $value): array
    {
        $rows = $this->filtered($filter, $value)->getQuery()->getResult();
        self::assertIsArray($rows);

        return $this->leadIdsOf($rows);
    }

    #[Test]
    public function aListIsOneExistsWithAnInAtTheLeafHoweverManyValuesItHolds(): void
    {
        $logger = $this->logger();

        $logger->reset();
        self::assertSame(['2'], $this->leadIds('filter[advertiser]=7'));
        $single = $logger->statements();

        $logger->reset();
        self::assertSame(['1', '2', '3', '4'], $this->leadIds('filter[advertiser]=4,7,9'));
        $three = $logger->statements();

        self::assertCount(\count($single), $three);

        $filtered = \array_values(\array_filter($three, static fn(string $sql): bool => \str_contains($sql, 'EXISTS')));
        self::assertNotSame([], $filtered);
        foreach ($filtered as $sql) {
            self::assertSame(1, \substr_count($sql, 'EXISTS'), $sql);
            self::assertSame(1, \substr_count($sql, 'IN (?, ?, ?)'), $sql);
        }
    }

    #[Test]
    public function aRelationshipLeafComparesTheIdOfTheEntityTheLastHopReached(): void
    {
        $dql = $this->filtered($this->resolved(WhereThroughIn::make('advertiser', 'campaign.advertiser')), '4,7')->getDQL();

        self::assertSame(1, \substr_count($dql, 'EXISTS('), $dql);
        self::assertMatchesRegularExpression('/INNER JOIN \w+\.advertiser (\w+) WHERE .* AND \1\.id IN \(:jsonapi_filter_\d+\)\)/', $dql);
    }

    #[Test]
    public function anEmptyListAddsNoIn(): void
    {
        $dql = $this->filtered($this->resolved(WhereThroughIn::make('advertiser', 'campaign.advertiser')), [])->getDQL();

        self::assertStringContainsString('1 = 0', $dql);
        self::assertStringNotContainsString('EXISTS', $dql);
    }

    private function filtered(WhereThroughIn $filter, mixed $value): QueryBuilder
    {
        $builder = $this->entityManager()->createQueryBuilder()->select('l')->from(LeadEntity::class, 'l')->orderBy('l.id');
        $handler = new DoctrineFilterHandler();
        $handler->apply($filter, $builder, $value);

        return $builder;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }
}
