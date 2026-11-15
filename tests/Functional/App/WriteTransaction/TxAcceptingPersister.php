<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface;

/**
 * The `txWidgets` persister: passes every write to the store's own, except that with
 * {@see TxScenario::$accept} set it accepts a create or update for asynchronous processing
 * instead of writing it and queues the job once the accept commits, and with
 * {@see TxScenario::$persisterThrows} set it throws once the store's write has run.
 */
final readonly class TxAcceptingPersister implements DataPersisterInterface, TransactionalDataPersisterInterface
{
    public function __construct(
        private DataPersisterInterface&TransactionalDataPersisterInterface $inner,
        private TxCallbacks $callbacks,
    ) {}

    public function supports(string $type): bool
    {
        return $type === 'txWidgets';
    }

    public function instantiate(string $type): object
    {
        return $this->inner->instantiate($type);
    }

    public function create(string $type, object $entity): object
    {
        return TxScenario::$accept ? $this->accepted() : $this->written($this->inner->create($type, $entity));
    }

    public function update(string $type, object $entity): object
    {
        return TxScenario::$accept ? $this->accepted() : $this->written($this->inner->update($type, $entity));
    }

    public function delete(string $type, object $entity): void
    {
        $this->inner->delete($type, $entity);
        $this->written($entity);
    }

    public function mutateRelationship(string $type, object $entity, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, bool $flush = true): object
    {
        return $this->written($this->inner->mutateRelationship($type, $entity, $relation, $linkage, $mode, $flush));
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function written(object $entity): object
    {
        if (TxScenario::$persisterThrows) {
            throw new AccessDenied('Refused by the test persister.');
        }

        return $entity;
    }

    private function accepted(): AcceptedForProcessing
    {
        // Queue the job only once the accept has committed.
        $this->callbacks->register('dispatch job');

        return AcceptedForProcessing::poll('https://example.test/jobs/1')->withMeta(['status' => 'queued']);
    }
}
