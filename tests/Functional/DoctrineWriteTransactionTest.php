<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\Doctrine\TxDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxNote;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxScenario;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxUser;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxWidget;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Service\ResetInterface;

/**
 * {@see WriteTransactionConformanceTestCase} on Doctrine over SQLite, where the before hook's
 * write is a real flush, plus what only a database connection can show: the nesting level a
 * hook runs at, that a rollback leaves the connection out of any transaction and the
 * `EntityManager` open, and that it keeps managed what the manager held before the write, such
 * as the security user, while nothing the failed write touched is flushed by a later flush.
 */
final class DoctrineWriteTransactionTest extends WriteTransactionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return TxDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $note = new TxNote();
        $note->body = 'seeded';
        $entityManager->persist($note);

        $first = new TxWidget();
        $first->name = 'first';
        $first->notes->add($note);
        $entityManager->persist($first);

        $second = new TxWidget();
        $second->name = 'second';
        $entityManager->persist($second);

        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aBeforeHookRunsInsideTheWritesTransaction(): void
    {
        TxScenario::$sideWriteOn = BeforeCreateEvent::class;

        self::assertSame(201, $this->handle('/txWidgets', 'POST', $this->widget('third'))->getStatusCode());
        self::assertSame(1, TxScenario::$levelSeen);
    }

    #[Test]
    public function anAtomicOperationRunsInTheBatchTransactionAlone(): void
    {
        TxScenario::$sideWriteOn = BeforeCreateEvent::class;

        $response = $this->atomic([['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, TxScenario::$levelSeen);
    }

    #[Test]
    public function aRollbackLeavesTheEntityManagerOpenWithoutWhatTheWriteTouched(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeUpdateEvent::class;

        self::assertSame(403, $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1'))->getStatusCode());

        $entityManager = $this->entityManager();
        self::assertTrue($entityManager->isOpen());
        foreach ($entityManager->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                self::assertFalse($entity instanceof TxNote && $entity->body === 'by BeforeSaveEvent', 'The rolled-back note is detached.');
                self::assertFalse($entity instanceof TxWidget && $entity->name === 'renamed', 'A widget still managed matches the database.');
            }
        }
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function aFailedCreateKeepsTheUserManagedAndLeavesNothingToFlush(): void
    {
        $user = $this->signIn();
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$persisterThrows = true;

        self::assertSame(403, $this->handle('/txWidgets', 'POST', $this->widget('third', notes: ['1']))->getStatusCode());

        $this->assertNothingLeftBehind($user);
    }

    #[Test]
    public function aFailedUpdateReloadsTheWidgetItChangedAndLeavesNothingToFlush(): void
    {
        $user = $this->signIn();
        $widget = $this->entityManager()->find(TxWidget::class, 1);
        self::assertInstanceOf(TxWidget::class, $widget);
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$persisterThrows = true;

        self::assertSame(403, $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1', notes: []))->getStatusCode());

        if ($this->entityManager()->contains($widget)) {
            self::assertSame('first', $widget->name);
            self::assertCount(1, $widget->notes);
        }
        $this->assertNothingLeftBehind($user);
    }

    #[Test]
    public function aFailedDeleteLeavesNothingToFlush(): void
    {
        $user = $this->signIn();
        self::assertNotNull($this->entityManager()->find(TxWidget::class, 1));
        TxScenario::$persisterThrows = true;

        self::assertSame(403, $this->handle('/txWidgets/1', 'DELETE')->getStatusCode());

        $this->assertNothingLeftBehind($user);
    }

    #[Test]
    public function aFailedRelationshipWriteReloadsTheParentAndLeavesNothingToFlush(): void
    {
        $user = $this->signIn();
        $widget = $this->entityManager()->find(TxWidget::class, 2);
        self::assertInstanceOf(TxWidget::class, $widget);
        TxScenario::$sideWriteOn = BeforeRelationshipMutateEvent::class;
        TxScenario::$persisterThrows = true;

        $response = $this->handle('/txWidgets/2/relationships/notes', 'POST', ['data' => [['type' => 'txNotes', 'id' => '1']]]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        if ($this->entityManager()->contains($widget)) {
            self::assertCount(0, $widget->notes);
        }
        $this->assertNothingLeftBehind($user);
    }

    #[Test]
    public function aFailedWritingActionLeavesNothingToFlush(): void
    {
        $user = $this->signIn();
        $widget = $this->entityManager()->find(TxWidget::class, 1);
        self::assertInstanceOf(TxWidget::class, $widget);
        TxScenario::$actionThrows = true;

        self::assertSame(403, $this->handle('/txWidgets/1/-actions/stamp', 'POST')->getStatusCode());

        if ($this->entityManager()->contains($widget)) {
            self::assertSame('first', $widget->name);
        }
        $this->assertNothingLeftBehind($user);
    }

    #[Test]
    public function aFailedAtomicBatchLeavesNothingToFlush(): void
    {
        $user = $this->signIn();
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;
        TxScenario::$throwForName = 'boom';

        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]],
            ['op' => 'update', 'ref' => ['type' => 'txWidgets', 'id' => '1'], 'data' => ['type' => 'txWidgets', 'id' => '1', 'attributes' => ['name' => 'renamed']]],
            ['op' => 'remove', 'ref' => ['type' => 'txWidgets', 'id' => '2']],
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'boom']]],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNothingLeftBehind($user);
    }

    #[Test]
    public function aFailedFlushLeavesNoTransactionOpen(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;

        // The name is unique, so the create's own flush fails after the hook's has run.
        $response = $this->handle('/txWidgets', 'POST', $this->widget('first'));

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($this->entityManager()->isOpen(), 'Doctrine closes the manager when a flush fails.');
        $this->assertNoTransactionOpen();

        // Symfony gives a worker's next request a fresh manager; the connection it shares is
        // out of the transaction, and the hook's note went with it.
        self::assertSame(1, $this->rowCount('tx_note'));
        self::assertSame(2, $this->rowCount('tx_widget'));
    }

    #[Test]
    public function aWorkerResetAfterAFailedFlushServesTheNextWrite(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        self::assertSame(500, $this->handle('/txWidgets', 'POST', $this->widget('first'))->getStatusCode());
        self::assertFalse($this->entityManager()->isOpen());

        // What a long-running worker does between requests.
        $resetter = static::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertTrue($this->entityManager()->isOpen());
        TxScenario::reset();
        self::assertSame(201, $this->handle('/txWidgets', 'POST', $this->widget('third'))->getStatusCode());
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second', 'third'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anUpdateAcceptedForProcessingLeavesTheResourceAsStored(): void
    {
        TxScenario::$accept = true;

        self::assertSame(202, $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1'))->getStatusCode());

        TxScenario::reset();
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    /**
     * Stores a user and keeps it managed, as the firewall does with the security user, for
     * the blame listener to stamp on what is persisted next.
     */
    private function signIn(): TxUser
    {
        $user = new TxUser();
        $user->name = 'ada';
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return TxScenario::$user = $user;
    }

    /**
     * The user loaded before the failed write is still managed, a later flush of a note the
     * listener blames on it succeeds, and that flush writes nothing the failed write left.
     */
    private function assertNothingLeftBehind(TxUser $user): void
    {
        $entityManager = $this->entityManager();
        self::assertTrue($entityManager->isOpen());
        self::assertTrue($entityManager->contains($user), 'The user loaded before the write stays managed.');
        $this->assertNoTransactionOpen();

        TxScenario::reset();
        TxScenario::$user = $user;
        $note = new TxNote();
        $note->body = 'later';
        $entityManager->persist($note);
        $entityManager->flush();

        self::assertSame($user, $note->createdBy);
        self::assertSame([['seeded', null], ['later', $user->id]], $this->rows('SELECT body, created_by_id FROM tx_note ORDER BY id'));
        self::assertSame([['first'], ['second']], $this->rows('SELECT name FROM tx_widget ORDER BY id'));
        self::assertSame([[1, 1]], $this->rows('SELECT * FROM tx_widget_note ORDER BY 1, 2'));
        self::assertSame(1, $this->rowCount('tx_user'));
    }

    /**
     * @return list<list<mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = [];
        foreach ($this->entityManager()->getConnection()->fetchAllNumeric($sql) as $row) {
            $rows[] = \array_map(static fn(mixed $value): mixed => \is_numeric($value) ? (int) $value : $value, $row);
        }

        return $rows;
    }

    protected function assertNoTransactionOpen(): void
    {
        self::assertSame(0, $this->entityManager()->getConnection()->getTransactionNestingLevel());
    }

    protected function detachPersistedState(): void
    {
        if ($this->entityManager()->isOpen()) {
            $this->entityManager()->clear();
        }
    }

    private function rowCount(string $table): int
    {
        $count = $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);

        return \is_numeric($count) ? (int) $count : -1;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
