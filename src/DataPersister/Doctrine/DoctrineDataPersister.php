<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister\Doctrine;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
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
 *
 * Relationship mutation ({@see mutateRelationship()}) resolves each linkage id to a
 * managed reference (`EntityManager::getReference()` on the *related* type's mapped
 * entity class) and mutates the parent's association — setting the to-one reference,
 * or adding/removing/replacing the to-many collection members — then flushes. When
 * the association is the inverse side of the mapping it also sets the owning side on
 * each related entity, so the foreign key the to-many is backed by is actually
 * written.
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

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        $property = $relation->column() ?? $relation->name();
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $relatedClass = $this->entityClassFor($relatedType);

        if ($linkage instanceof ToOneRelationship) {
            $reference = $linkage->resourceIdentifier?->id !== null
                ? $this->entityManager->getReference($relatedClass, $linkage->resourceIdentifier->id)
                : null;

            $entity->{$property} = $reference;
        } else {
            $this->mutateToMany($entity, $property, $relatedClass, $linkage, $mode);
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        return $entity;
    }

    /**
     * @param class-string $relatedClass
     */
    private function mutateToMany(
        object $entity,
        string $property,
        string $relatedClass,
        ToManyRelationship $linkage,
        Mode $mode,
    ): void {
        $collection = $entity->{$property};
        if (!$collection instanceof Collection) {
            return;
        }

        $owningField = $this->inverseOwningField($entity, $property);

        $incomingIds = \array_values(\array_filter(
            $linkage->getResourceIdentifierIds(),
            static fn(?string $id): bool => $id !== null,
        ));

        if ($mode === Mode::Replace) {
            // Detach the existing members (clear the owning-side FK on each) then
            // rebuild the collection from the incoming references.
            foreach ($collection->toArray() as $member) {
                if (\is_object($member)) {
                    $this->setOwningSide($member, $owningField, null);
                }
            }
            $collection->clear();
            foreach ($incomingIds as $id) {
                $this->addMember($collection, $relatedClass, $id, $entity, $owningField);
            }

            return;
        }

        if ($mode === Mode::Add) {
            foreach ($incomingIds as $id) {
                $this->addMember($collection, $relatedClass, $id, $entity, $owningField);
            }

            return;
        }

        // Mode::Remove — drop the incoming members and clear their owning-side FK.
        foreach ($incomingIds as $id) {
            $reference = $this->entityManager->getReference($relatedClass, $id);
            if ($reference === null) {
                continue;
            }
            $collection->removeElement($reference);
            $this->setOwningSide($reference, $owningField, null);
        }
    }

    /**
     * Resolves a managed reference for `$id` and adds it to the collection
     * (idempotent — a member already present is not duplicated), then sets the
     * owning side on it. A no-op when no reference resolves.
     *
     * @param Collection<int, object> $collection
     * @param class-string            $relatedClass
     */
    private function addMember(
        Collection $collection,
        string $relatedClass,
        string $id,
        object $owner,
        ?string $owningField,
    ): void {
        $reference = $this->entityManager->getReference($relatedClass, $id);
        if ($reference === null) {
            return;
        }

        if (!$collection->contains($reference)) {
            $collection->add($reference);
        }
        $this->setOwningSide($reference, $owningField, $owner);
    }

    /**
     * The owning-side field on the related entity for an inverse-side association,
     * or `null` when the parent's association is itself the owning side (so the
     * collection write alone carries the foreign key).
     */
    private function inverseOwningField(object $entity, string $property): ?string
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        if (!$metadata->hasAssociation($property)) {
            return null;
        }

        $mapping = $metadata->getAssociationMapping($property);

        if ($mapping->isOwningSide()) {
            return null;
        }

        // `mappedBy` lives on the inverse-side mapping; read it through the
        // mapping's array access so the lookup is robust across the ORM 3 mapping
        // class hierarchy.
        $mappedBy = $mapping['mappedBy'] ?? null;

        return \is_string($mappedBy) ? $mappedBy : null;
    }

    /**
     * Sets the owning-side association on a related entity (no-op when the parent
     * side already owns the foreign key).
     */
    private function setOwningSide(object $member, ?string $owningField, ?object $owner): void
    {
        if ($owningField === null) {
            return;
        }

        $member->{$owningField} = $owner;
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
