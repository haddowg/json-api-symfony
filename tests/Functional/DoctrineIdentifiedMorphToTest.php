<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph\IdentifiedCampaign;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph\IdentifiedMorphDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph\IdentifiedMorphFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see IdentifiedMorphToConformanceTestCase} against the Doctrine provider, with the
 * query budget the pair buys: linkage loads only the scoped agencies, in one query,
 * and an include loads each target type in one query.
 */
final class DoctrineIdentifiedMorphToTest extends IdentifiedMorphToConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdentifiedMorphDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (IdentifiedMorphFixtures::graph() as $items) {
            foreach ($items as $item) {
                $entityManager->persist($item);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function storeTarget(int $campaignId, string $type, int $id): void
    {
        $this->entityManager()
            ->createQuery(\sprintf('UPDATE %s c SET c.targetType = :type, c.targetId = :id WHERE c.id = :campaign', IdentifiedCampaign::class))
            ->execute(['type' => $type, 'id' => $id, 'campaign' => $campaignId]);
    }

    #[Test]
    #[Group('spec:resource-linkage')]
    public function theLinkageOfAPageLoadsOnlyTheGuardedTypeInOneQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->document('/campaigns');

        self::assertSame([], $this->selectsFrom($logger, 'identified_advertiser'), \implode("\n", $logger->statements()));
        self::assertCount(1, $this->selectsFrom($logger, 'identified_agency'), \implode("\n", $logger->statements()));
        self::assertSame(2, $logger->queryCount(), \implode("\n", $logger->statements()));
    }

    #[Test]
    #[Group('spec:resource-linkage')]
    public function anUnguardedTypesLinkageLoadsNoTarget(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->document('/campaigns/1');
        $this->document('/campaigns/9/relationships/target');

        self::assertSame([], $this->selectsFrom($logger, 'identified_advertiser'), \implode("\n", $logger->statements()));
        self::assertSame(2, $logger->queryCount(), \implode("\n", $logger->statements()));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointLoadsAGuardedTypesTargetInOneQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->document('/campaigns/3/relationships/target');

        self::assertCount(1, $this->selectsFrom($logger, 'identified_agency'), \implode("\n", $logger->statements()));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeLoadsEachTargetTypeInOneQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->document('/campaigns?include=target');

        self::assertCount(1, $this->selectsFrom($logger, 'identified_advertiser'), \implode("\n", $logger->statements()));
        self::assertCount(1, $this->selectsFrom($logger, 'identified_agency'), \implode("\n", $logger->statements()));
        self::assertSame(3, $logger->queryCount(), \implode("\n", $logger->statements()));
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

    /**
     * The SELECT statements that read `$table`.
     *
     * @return list<string>
     */
    private function selectsFrom(QueryCountingLogger $logger, string $table): array
    {
        return \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_starts_with(\strtolower(\ltrim($sql)), 'select')
                && \preg_match('/\bfrom ' . \preg_quote($table, '/') . '\b/i', $sql) === 1,
        ));
    }
}
