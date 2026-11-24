<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MorphKeysDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\MorphKeysFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see MorphKeysConformanceTestCase} against the Doctrine provider, which admits the stored
 * ids of a scoped type through the related read's id path.
 */
final class DoctrineMorphKeysTest extends MorphKeysConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MorphKeysDoctrineKernel::class;
    }

    #[Test]
    public function aTextColumnAgainstAnIntegerIdAdmitsTheStoredIdsInsteadOfASubquery(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle('/mkNotes?filter[subject][type]=mkCounters');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $statements = \array_map('\\strtolower', $logger->statements());
        $distinct = \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'select distinct') && \str_contains($sql, 'mk_note'));
        self::assertCount(1, $distinct, "the distinct stored ids are read once:\n" . \implode("\n", $statements));
        $compared = \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'from mk_note') && \str_contains($sql, 'from mk_counter'));
        self::assertSame([], $compared, "no statement compares the text column with the integer ids in SQL:\n" . \implode("\n", $statements));
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (MorphKeysFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
