<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;

/**
 * The reference Doctrine ORM write persister — the write twin of the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider},
 * wired only when `doctrine/orm` is installed **and** at least one resource maps
 * an entity (the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * removes it otherwise), and registered as the `-128` fallback so an application
 * persister shadows it for the types it supports.
 *
 * It commits an entity core's hydrator has already populated, through the same
 * `EntityManager` the provider reads with: an update/delete target loaded by the
 * provider (through any scoping its extension pipeline applies at
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose::FetchOne}) is
 * a managed instance, so flushing commits exactly the hydrated changes. Create
 * persists a new instance; the `type → entity-class` map (populated by the same
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * from each resource's `#[AsJsonApiResource(entity: …)]`) is what {@see instantiate()}
 * constructs.
 */
final class DoctrineDataPersister implements DataPersisterInterface
{
    /**
     * @param array<string, class-string> $entityClassByType a `type → entity FQCN` map
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
    ) {}

    public function supports(string $type): bool
    {
        return isset($this->entityClassByType[$type]);
    }

    public function instantiate(string $type): object
    {
        $entityClass = $this->entityClassFor($type);

        return new $entityClass();
    }

    public function create(string $type, object $entity): object
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }

    public function update(string $type, object $entity): object
    {
        // The target was loaded through the provider's EntityManager, so it is a
        // managed instance the hydrator mutated in place; flushing commits it.
        $this->entityManager->flush();

        return $entity;
    }

    public function delete(string $type, object $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    /**
     * @return class-string
     */
    private function entityClassFor(string $type): string
    {
        return $this->entityClassByType[$type]
            ?? throw new \LogicException(\sprintf('No Doctrine entity class is mapped for JSON:API type "%s".', $type));
    }
}
