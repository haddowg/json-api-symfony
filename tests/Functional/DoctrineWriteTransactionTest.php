<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\Doctrine\TxDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxNote;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxScenario;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxWidget;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Service\ResetInterface;

/**
 * {@see WriteTransactionConformanceTestCase} on Doctrine over SQLite, where the before hook's
 * write is a real flush, plus what only a database connection can show: the nesting level a
 * hook runs at, and that a rollback leaves the connection out of any transaction and the
 * `EntityManager` open and cleared.
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
    public function aRollbackLeavesTheEntityManagerOpenAndCleared(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeUpdateEvent::class;

        self::assertSame(403, $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1'))->getStatusCode());

        $entityManager = $this->entityManager();
        self::assertTrue($entityManager->isOpen());
        self::assertSame([], $entityManager->getUnitOfWork()->getIdentityMap());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function aFailedFlushLeavesNoTransactionOpenAndAResetManagerServesTheNextRequest(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;

        // The name is unique, so the create's own flush fails after the hook's has run.
        $response = $this->handle('/txWidgets', 'POST', $this->widget('first'));

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNoTransactionOpen();
        self::assertFalse($this->entityManager()->isOpen(), 'Doctrine closes the manager when a flush fails.');

        // What a long-running worker does between requests.
        $resetter = static::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        TxScenario::reset();
        self::assertSame(['seeded'], $this->notes());
        self::assertSame(201, $this->handle('/txWidgets', 'POST', $this->widget('third'))->getStatusCode());
        self::assertSame(['first', 'second', 'third'], $this->widgetNames());
    }

    #[Test]
    public function anUpdateAcceptedForProcessingLeavesTheResourceAsStored(): void
    {
        TxScenario::$accept = true;

        self::assertSame(202, $this->handle('/txWidgets/1', 'PATCH', $this->widget('renamed', id: '1'))->getStatusCode());

        TxScenario::reset();
        self::assertSame(['first', 'second'], $this->widgetNames());
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

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
