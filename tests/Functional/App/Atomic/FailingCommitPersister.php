<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Atomic;

use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface;

/**
 * A transactional in-memory persister whose {@see commit()} always throws — the
 * witness for the executor's multi-persister commit boundary (the finding-3 case).
 *
 * It delegates every write to a real {@see InMemoryDataPersister} (so the batch's
 * writes apply and snapshot/restore work normally), but fails at commit time. In a
 * batch spanning this persister and another DISTINCT transactional persister, the
 * other commits first (its writes become durable), then this one throws: the
 * executor must roll back the persisters that have NOT yet committed and re-raise,
 * which the loop renders as a rolled-back error document. The already-committed
 * persister's writes stand — the documented single-transactional-persister-per-batch
 * limitation of the all-or-nothing guarantee.
 */
final class FailingCommitPersister implements DataPersisterInterface, TransactionalDataPersisterInterface
{
    public function __construct(private readonly InMemoryDataPersister $delegate) {}

    public function supports(string $type): bool
    {
        return $this->delegate->supports($type);
    }

    public function beginTransaction(): void
    {
        $this->delegate->beginTransaction();
    }

    public function commit(): void
    {
        throw new ApplicationError();
    }

    public function rollback(): void
    {
        $this->delegate->rollback();
    }

    public function instantiate(string $type): object
    {
        return $this->delegate->instantiate($type);
    }

    public function create(string $type, object $entity): object
    {
        return $this->delegate->create($type, $entity);
    }

    public function update(string $type, object $entity): object
    {
        return $this->delegate->update($type, $entity);
    }

    public function delete(string $type, object $entity): void
    {
        $this->delegate->delete($type, $entity);
    }

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        return $this->delegate->mutateRelationship($type, $entity, $relation, $linkage, $mode, $flush);
    }
}
