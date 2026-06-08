<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApiBundle\DataProvider\InMemoryStore;

/**
 * The in-memory write witness: it persists through the same {@see InMemoryStore}
 * an {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider} reads from,
 * so a created, updated, or deleted resource is immediately visible to reads —
 * the write twin of that provider, mirroring how the Doctrine pair shares one
 * `EntityManager`.
 *
 * One instance answers for a single `$type`. A `$factory` produces the blank
 * instance the handler hydrates on create (the store holds no class metadata of
 * its own).
 */
final class InMemoryDataPersister implements DataPersisterInterface
{
    /**
     * @param InMemoryStore       $store   the store shared with the read provider
     * @param \Closure(): object  $factory builds a fresh, unpopulated instance for create
     */
    public function __construct(
        private readonly string $type,
        private readonly InMemoryStore $store,
        private readonly \Closure $factory,
    ) {}

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function instantiate(string $type): object
    {
        return ($this->factory)();
    }

    public function create(string $type, object $entity): object
    {
        $this->store->save($entity);

        return $entity;
    }

    public function update(string $type, object $entity): object
    {
        $this->store->save($entity);

        return $entity;
    }

    public function delete(string $type, object $entity): void
    {
        $this->store->remove($entity);
    }
}
