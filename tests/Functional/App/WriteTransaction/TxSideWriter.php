<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface;

/**
 * Writes a note straight through the `txNotes` persister, which flushes on Doctrine, and
 * opens and commits a transaction of its own the way an application hook does: on the
 * `EntityManager` under Doctrine, on the notes persister in memory.
 */
final readonly class TxSideWriter
{
    public function __construct(
        private DataPersisterRegistry $persisters,
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    public function write(string $body): void
    {
        $persister = $this->persisters->forType('txNotes');
        $note = $persister->instantiate('txNotes');
        \assert($note instanceof TxNote);
        $note->body = $body;

        TxScenario::$levelSeen = $this->entityManager?->getConnection()->getTransactionNestingLevel();
        $persister->create('txNotes', $note);
    }

    public function begin(): void
    {
        if ($this->entityManager !== null) {
            $this->entityManager->beginTransaction();

            return;
        }

        $this->notes()->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->entityManager !== null) {
            $this->entityManager->commit();

            return;
        }

        $this->notes()->commit();
    }

    private function notes(): TransactionalDataPersisterInterface
    {
        $persister = $this->persisters->forType('txNotes');
        \assert($persister instanceof TransactionalDataPersisterInterface);

        return $persister;
    }
}
