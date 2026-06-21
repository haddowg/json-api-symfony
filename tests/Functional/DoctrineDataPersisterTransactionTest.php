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
 * rollback discards the write entirely AND closes the (now-tainted) EntityManager.
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

        // The transaction is closed and the EntityManager is closed (a rolled-back
        // unit of work is tainted; the request is ending).
        self::assertFalse($connection->isTransactionActive());
        self::assertFalse($entityManager->isOpen());

        // The INSERT was NOT durable: a raw query on the same connection finds no
        // row (the EM is closed, so query through the connection directly).
        $count = $connection->fetchOne('SELECT COUNT(*) FROM tag');
        self::assertSame(0, \is_numeric($count) ? (int) $count : -1);
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
