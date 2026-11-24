<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler;
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
        $statements = $this->statementsOf('/mkNotes?filter[subject][type]=mkCounters');

        self::assertCount(1, $this->containing($statements, 'select distinct', 'mk_note'), "the distinct stored ids are read once:\n" . \implode("\n", $statements));
        self::assertSame([], $this->containing($statements, 'from mk_note', 'from mk_counter'), "no statement compares the text column with the integer ids in SQL:\n" . \implode("\n", $statements));
    }

    #[Test]
    public function aTextColumnAgainstAStringIdKeepsTheSubquery(): void
    {
        $statements = $this->statementsOf('/mkNotes?filter[subject][type]=mkCodes');

        self::assertSame([], $this->containing($statements, 'select distinct'), "no stored ids are read first:\n" . \implode("\n", $statements));
        self::assertCount(1, $this->containing($statements, 'from mk_note', 'from mk_code'), "one statement compares the ids in SQL:\n" . \implode("\n", $statements));
    }

    #[Test]
    public function aLongListOfAdmittedIdsBindsInChunks(): void
    {
        DoctrineFilterHandler::chunkMorphIdsBy(1);
        try {
            $statements = $this->statementsOf('/mkNotes?filter[subject][type]=mkBadges');
            $response = $this->handle('/mkNotes?filter[subject][type]=mkBadges');
        } finally {
            DoctrineFilterHandler::chunkMorphIdsBy(2000);
        }

        $ids = [];
        $rows = $this->decode($response)['data'] ?? null;
        self::assertIsArray($rows);
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $ids[] = $row['id'] ?? null;
        }
        \sort($ids);
        self::assertSame(['3', '4'], $ids);
        $chunked = $this->containing($statements, 'from mk_note', ' in (?) or ');
        self::assertNotSame([], $chunked, "the admitted ids bind as several lists:\n" . \implode("\n", $statements));
    }

    /**
     * The lower-cased SQL each statement of a request ran.
     *
     * @return list<string>
     */
    private function statementsOf(string $path): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return \array_map('\\strtolower', $logger->statements());
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function containing(array $statements, string ...$needles): array
    {
        return \array_values(\array_filter($statements, static function (string $sql) use ($needles): bool {
            foreach ($needles as $needle) {
                if (!\str_contains($sql, $needle)) {
                    return false;
                }
            }

            return true;
        }));
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
