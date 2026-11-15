<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDataPersister;
use haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\TagEntity;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Doctrine persister's {@see TransactionalDataPersisterInterface} capability —
 * the seam the Atomic Operations executor drives. begin → per-operation write
 * (its flush is non-durable while the transaction is open, yet materialises the
 * store-generated id immediately) → commit persists durably; begin → write →
 * rollback discards the write and leaves the EntityManager open, holding only what it
 * managed before the transaction began.
 *
 * Exercised against the real Doctrine + in-memory SQLite functional kernel over
 * the store-provided `AUTO` int-PK {@see TagEntity}, the simple write witness.
 */
final class DoctrineDataPersisterTransactionTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[Test]
    public function beginThenWriteThenCommitPersistsDurably(): void
    {
        $persister = $this->persister();

        $persister->beginTransaction();

        // The per-operation write (create) flushes INSIDE the open transaction.
        $tag = new TagEntity(name: 'committed');
        self::assertNull($tag->id);
        $persister->create('tags', $tag);

        // The store-generated AUTO id is materialised immediately by the in-tx
        // INSERT — the contract the executor relies on (a later operation in the
        // batch can reference this id).
        self::assertNotNull($tag->id);

        $persister->commit();

        // Durable after commit: a fresh read finds the row.
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(TagEntity::class, $tag->id);
        self::assertInstanceOf(TagEntity::class, $reloaded);
        self::assertSame('committed', $reloaded->name);
    }

    #[Test]
    public function beginThenWriteThenRollbackDiscardsTheWriteAndClosesTheManager(): void
    {
        $persister = $this->persister();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();

        $persister->beginTransaction();
        self::assertTrue($connection->isTransactionActive());

        $tag = new TagEntity(name: 'in-flight');
        $persister->create('tags', $tag);
        // Materialised in-transaction even on the path that will roll back.
        self::assertNotNull($tag->id);

        $persister->rollback();

        // The transaction is rolled back and the unit of work cleared, but the
        // EntityManager is left OPEN and reusable (the request still renders the error,
        // and a worker reuses the manager on its next message).
        self::assertFalse($connection->isTransactionActive());
        self::assertTrue($entityManager->isOpen());

        // The INSERT was NOT durable: a fresh query finds no row.
        $count = $connection->fetchOne('SELECT COUNT(*) FROM tag');
        self::assertSame(0, \is_numeric($count) ? (int) $count : -1);
    }

    #[Test]
    public function aRollbackAlsoClosesATransactionAHookOpenedInsideIt(): void
    {
        $persister = $this->persister();
        $connection = $this->entityManager()->getConnection();

        $persister->beginTransaction();
        // A hook opens its own and throws before it commits.
        $this->entityManager()->beginTransaction();
        $persister->create('tags', new TagEntity(name: 'hooked'));
        self::assertSame(2, $connection->getTransactionNestingLevel());

        $persister->rollback();

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $this->tagCount());
    }

    #[Test]
    public function aNestedRollbackKeepsWhatTheOuterTransactionHeld(): void
    {
        $persister = $this->persister();
        $entityManager = $this->entityManager();

        $persister->beginTransaction();
        $outer = new TagEntity(name: 'outer');
        $persister->create('tags', $outer);

        $persister->beginTransaction();
        $inner = new TagEntity(name: 'inner');
        $persister->create('tags', $inner);
        $outer->name = 'renamed';
        $persister->rollback();

        self::assertTrue($entityManager->contains($outer));
        self::assertSame('outer', $outer->name, 'The outer row is reloaded as the inner rollback left it.');
        self::assertFalse($entityManager->contains($inner));

        // Without savepoints DBAL 3 can only roll the outer level back now.
        $persister->rollback();

        self::assertFalse($entityManager->contains($outer));
        self::assertSame([], $this->tagNames());
    }

    #[Test]
    public function aRollbackDetachesWhatTheWritePersistedWithoutFlushing(): void
    {
        $persister = $this->persister();
        $entityManager = $this->entityManager();
        $kept = new TagEntity(name: 'kept');
        $entityManager->persist($kept);
        $entityManager->flush();

        $persister->beginTransaction();
        $pending = new TagEntity(name: 'pending');
        $entityManager->persist($pending);
        $entityManager->remove($kept);
        $persister->rollback();

        self::assertFalse($entityManager->contains($pending));
        self::assertFalse($entityManager->contains($kept), 'An entity the write removed is detached, not deleted later.');
        $entityManager->flush();

        self::assertSame(['kept'], $this->tagNames());
    }

    #[Test]
    public function aCommitLeavesATransactionAHookOpenedForTheHookToCommit(): void
    {
        $persister = $this->persister();
        $connection = $this->entityManager()->getConnection();

        $persister->beginTransaction();
        $this->entityManager()->beginTransaction();
        $persister->create('tags', new TagEntity(name: 'hooked'));

        $persister->commit();
        self::assertSame(1, $connection->getTransactionNestingLevel());

        // The hook's own commit, in an after hook, closes the outermost level.
        $this->entityManager()->commit();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(1, $this->tagCount());
    }

    #[Test]
    public function aRollbackAfterAFailedFlushLeavesNoTransactionOpen(): void
    {
        $persister = $this->persister();
        $connection = $this->entityManager()->getConnection();

        $persister->beginTransaction();
        // SQLite's DDL is transactional, so the rollback brings the table back.
        $connection->executeStatement('DROP TABLE tag');

        try {
            $persister->create('tags', new TagEntity(name: 'lost'));
            self::fail('The flush should fail.');
        } catch (\Doctrine\DBAL\Exception) {
        }
        self::assertFalse($this->entityManager()->isOpen());
        self::assertSame(1, $connection->getTransactionNestingLevel());

        $persister->rollback();

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $this->tagCount());
    }

    #[Test]
    public function aResetRollsBackATransactionTheWorkerLeftOpen(): void
    {
        $persister = $this->persister();
        $connection = $this->entityManager()->getConnection();

        $persister->beginTransaction();
        $persister->create('tags', new TagEntity(name: 'stranded'));

        $persister->reset();

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, $this->tagCount());
    }

    /**
     * @return list<string>
     */
    private function tagNames(): array
    {
        $names = $this->entityManager()->getConnection()->fetchFirstColumn('SELECT name FROM tag ORDER BY id');

        return \array_values(\array_filter($names, \is_string(...)));
    }

    private function tagCount(): int
    {
        $count = $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM tag');

        return \is_numeric($count) ? (int) $count : -1;
    }

    private function persister(): DoctrineDataPersister&TransactionalDataPersisterInterface
    {
        $persister = static::getContainer()->get(DoctrineDataPersister::class);
        \assert($persister instanceof DoctrineDataPersister);

        return $persister;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
