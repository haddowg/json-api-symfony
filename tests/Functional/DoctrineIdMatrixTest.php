<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixSeed;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImNote;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see IdMatrixConformanceTestCase} on Doctrine. On sqlite a `uuid` and a `ulid` are 16
 * bytes and the custom type an integer, so every key compares in its database form.
 */
final class DoctrineIdMatrixTest extends IdMatrixConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdMatrixDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $entityManager = $this->entityManager();
        $schema = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        foreach (IdMatrixSeed::build() as $rows) {
            foreach ($rows as $row) {
                $entityManager->persist($row);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aPolymorphicWriteStoresTheMappedTypeAndTheKey(): void
    {
        self::assertSame(200, $this->handle('/im-posts/2/relationships/notes', 'POST', ['data' => [['type' => 'im-notes', 'id' => '4']]])->getStatusCode());

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $note = $entityManager->find(ImNote::class, 4);
        self::assertInstanceOf(ImNote::class, $note);
        self::assertSame('im-posts', $note->subjectType);
        self::assertSame(2, $note->subjectId);
    }

    #[Test]
    public function aMalformedGuidMatchesNothing(): void
    {
        self::assertSame(200, $this->handle('/im-notes?filter[guidOwner]=not-a-guid')->getStatusCode());
        self::assertSame(404, $this->handle('/im-guid-owners/not-a-guid/notes')->getStatusCode());
    }

    #[Test]
    public function aMorphManyWhoseFormsDifferOnlyInCaseReadsAPageOfParentsInOneWindowedQuery(): void
    {
        $statements = $this->statementsOf('/im-uuid-owners?include=refNotes');
        $refs = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'reftype in')));

        self::assertCount(1, $refs, \implode("\n", $statements));
        self::assertStringContainsString('row_number() over (partition by', $refs[0]);
        self::assertStringContainsString('lower(i0_.refid) as', $refs[0]);
        // The column itself is compared as stored, so an index on it serves the query.
        self::assertStringContainsString('i0_.refid in (', $refs[0]);
        self::assertStringNotContainsString('lower(i0_.refid) in', $refs[0]);
    }

    #[Test]
    public function aMorphManyWhoseEncoderStoresAnotherFormReadsEachParentOnItsOwn(): void
    {
        $statements = $this->statementsOf('/im-prefix-owners?include=refNotes');
        $refs = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'reftype in')));

        self::assertSame([], \array_values(\array_filter($refs, static fn(string $sql): bool => \str_contains($sql, 'row_number()'))));
        self::assertCount(3, $refs);
    }

    /**
     * @return list<string>
     */
    private function statementsOf(string $path): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();
        self::assertSame(200, $this->handle($path)->getStatusCode());

        return \array_map('\strtolower', $logger->statements());
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
