<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Atomic;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Tag;

/**
 * A `tags` persister that does NOT implement
 * {@see \haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface} —
 * the witness for the atomic executor's pre-flight refusal: a batch touching `tags`
 * is rejected (`403`) before any transaction opens, because all-or-nothing cannot be
 * guaranteed for a non-transactional type.
 *
 * It still persists single ops fine (the CRUD path needs no transaction), so the
 * type works normally outside a batch.
 */
final class NonTransactionalTagPersister implements DataPersisterInterface
{
    /**
     * @var array<array-key, Tag>
     */
    private array $tags = [];

    private int $nextId = 1;

    public function supports(string $type): bool
    {
        return $type === 'tags';
    }

    public function instantiate(string $type): object
    {
        return new Tag();
    }

    public function create(string $type, object $entity): object
    {
        \assert($entity instanceof Tag);
        $id = (string) $this->nextId++;
        $entity->id = (int) $id;
        $this->tags[$id] = $entity;

        return $entity;
    }

    public function update(string $type, object $entity): object
    {
        \assert($entity instanceof Tag);
        $this->tags[(string) $entity->id] = $entity;

        return $entity;
    }

    public function delete(string $type, object $entity): void
    {
        \assert($entity instanceof Tag);
        unset($this->tags[(string) $entity->id]);
    }

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        return $entity;
    }
}
