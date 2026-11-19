<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine\ThroughDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\ThroughFixtures;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ThroughRelationsConformanceTestCase} against the Doctrine provider, where a
 * filter is one correlated EXISTS and a sort left-joins each to-one, the hidden
 * advertiser kept out by a query extension in both.
 */
final class DoctrineThroughRelationsTest extends ThroughRelationsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ThroughDoctrineTestKernel::class;
    }

    protected static function brokenKernel(): string
    {
        return ThroughDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ThroughFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aFilterIsOneExistsAndASortLeftJoinsEachToOne(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        \assert($logger instanceof QueryCountingLogger);

        $logger->reset();
        self::assertSame(['1', '4', '5'], $this->leadIds('filter[templateName][]=Welcome&filter[templateName][]=Survey'));
        $filtered = \array_values(\array_filter($logger->statements(), static fn(string $sql): bool => \str_contains($sql, 'thr_lead_templates')));
        self::assertNotSame([], $filtered);
        foreach ($filtered as $sql) {
            self::assertSame(1, \substr_count($sql, 'EXISTS'), $sql);
        }

        $logger->reset();
        $this->orderedLeadIds('sort=advertiserName,campaignStart');
        $sorted = \array_values(\array_filter($logger->statements(), static fn(string $sql): bool => \str_contains($sql, 'ORDER BY')));
        self::assertNotSame([], $sorted);
        // Both sorts share the campaign join; the advertiser join is scoped by its extension.
        self::assertSame(2, \substr_count($sorted[0], 'LEFT JOIN'), $sorted[0]);
        self::assertStringContainsString('hidden', $sorted[0]);
    }
}
