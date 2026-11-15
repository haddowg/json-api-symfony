<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;

/**
 * The entities an `EntityManager` managed at one moment, so that everything it took on after
 * can be let go while those stay managed.
 *
 * Letting go clears the unit of work and registers the kept entities again with the identity
 * and original data they hold, rather than detaching the newer ones one by one: a detach
 * cascades along `cascade: detach` (and `all`) mappings into kept entities, and leaves behind
 * the orphan removals and collection deletions the newer entities scheduled, which a later
 * flush would still run. A kept entity that still points at an entity let go, or that holds a
 * collection change not yet flushed, is reloaded from the database, without cascading, so no
 * later flush finds a detached entity through it and inserts it again.
 *
 * @internal
 */
final class UnitOfWorkSnapshot
{
    /**
     * @param array<int, object>                                                  $entities the managed entities, keyed by object id
     * @param array<int, array{array<string, mixed>, array<string, list<mixed>|null>}> $states   for a transaction, each entity's original data and loaded collections then
     */
    private function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entities,
        private readonly array $states,
    ) {}

    /**
     * Before a transaction, recording what each entity holds so {@see rolledBack()} can tell
     * which ones the transaction changed.
     */
    public static function beforeTransaction(EntityManagerInterface $entityManager): self
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $entities = self::managed($unitOfWork);
        $states = [];
        foreach ($entities as $oid => $entity) {
            $states[$oid] = [$unitOfWork->getOriginalEntityData($entity), self::loadedCollections($entityManager, $entity)];
        }

        return new self($entityManager, $entities, $states);
    }

    /**
     * Before a run of batched reads, for {@see letGo()} between batches.
     */
    public static function beforeBatches(EntityManagerInterface $entityManager): self
    {
        return new self($entityManager, self::managed($entityManager->getUnitOfWork()), []);
    }

    /**
     * After the transaction rolled back: lets go of everything the transaction loaded,
     * persisted or inserted, and of what it deleted or scheduled for deletion, and reloads a
     * kept entity the transaction changed, flushed or not, so it matches the database again.
     */
    public function rolledBack(): void
    {
        $this->restore(true);
    }

    /**
     * Between batches: lets go of everything loaded or persisted since the snapshot. A kept
     * entity keeps its unflushed field changes, unless it points at an entity let go or holds
     * a changed collection, when it is reloaded.
     */
    public function letGo(): void
    {
        $this->restore(false);
    }

    private function restore(bool $rolledBack): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $current = self::managed($unitOfWork);
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $current[\spl_object_id($entity)] = $entity;
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $current[\spl_object_id($entity)] = $entity;
        }

        $kept = [];
        foreach ($this->entities as $oid => $entity) {
            if (isset($current[$oid]) && $unitOfWork->getEntityState($entity) === UnitOfWork::STATE_MANAGED && !$unitOfWork->isScheduledForInsert($entity)) {
                $kept[$oid] = $entity;
            }
        }
        $released = \array_diff_key($current + $this->entities, $kept);

        $reload = [];
        foreach ($kept as $oid => $entity) {
            if ($unitOfWork->isUninitializedObject($entity)) {
                continue;
            }
            if (($rolledBack && $this->changed($oid, $entity)) || $this->hasPendingCollection($entity) || $this->refersTo($entity, $released)) {
                $reload[$oid] = $entity;
            }
        }

        if ($released === [] && $reload === []) {
            return;
        }

        $registered = [];
        foreach ($kept as $oid => $entity) {
            $registered[$oid] = [
                $unitOfWork->getEntityIdentifier($entity),
                $unitOfWork->getOriginalEntityData($entity),
                $unitOfWork->isReadOnly($entity),
                $unitOfWork->isScheduledForDirtyCheck($entity),
            ];
        }

        $this->entityManager->clear();

        foreach ($kept as $oid => $entity) {
            [$id, $data, $readOnly, $dirtyCheck] = $registered[$oid];
            $unitOfWork->registerManaged($entity, $id, $data);
            if ($readOnly) {
                $unitOfWork->markReadOnly($entity);
            }
            if ($dirtyCheck) {
                $unitOfWork->scheduleForDirtyCheck($entity);
            }
        }

        foreach ($reload as $entity) {
            $metadata = $this->entityManager->getClassMetadata($entity::class);
            $unitOfWork->getEntityPersister($metadata->getName())->refresh(
                \array_combine($metadata->getIdentifierFieldNames(), $unitOfWork->getEntityIdentifier($entity)),
                $entity,
            );
        }
    }

    /**
     * Whether the entity differs from what it held at the snapshot, or from its original
     * data now: a flush in the transaction changed the one, an unflushed change the other.
     */
    private function changed(int $oid, object $entity): bool
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $original = $unitOfWork->getOriginalEntityData($entity);
        [$then, $collections] = $this->states[$oid];
        if ($original !== $then || self::loadedCollections($this->entityManager, $entity) !== $collections) {
            return true;
        }

        $metadata = $this->entityManager->getClassMetadata($entity::class);
        foreach ([...$metadata->getFieldNames(), ...$metadata->getAssociationNames()] as $field) {
            if (\array_key_exists($field, $original) && $metadata->getFieldValue($entity, $field) !== $original[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a collection of the entity holds a change not yet flushed, which may have
     * scheduled an orphan removal or a collection deletion that letting go discards.
     */
    private function hasPendingCollection(object $entity): bool
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        foreach ($metadata->getAssociationNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            if ($value instanceof PersistentCollection && $value->isDirty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, object> $released
     */
    private function refersTo(object $entity, array $released): bool
    {
        if ($released === []) {
            return false;
        }

        $metadata = $this->entityManager->getClassMetadata($entity::class);
        foreach ($metadata->getAssociationNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            $related = match (true) {
                $value instanceof PersistentCollection => $value->unwrap()->toArray(),
                $value instanceof Collection => $value->toArray(),
                \is_array($value) => $value,
                \is_object($value) => [$value],
                default => [],
            };
            foreach ($related as $item) {
                if (\is_object($item) && isset($released[\spl_object_id($item)])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, object>
     */
    private static function managed(UnitOfWork $unitOfWork): array
    {
        $managed = [];
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                $managed[\spl_object_id($entity)] = $entity;
            }
        }

        return $managed;
    }

    /**
     * Each to-many collection of the entity: its elements when loaded, else null.
     *
     * @return array<string, list<mixed>|null>
     */
    private static function loadedCollections(EntityManagerInterface $entityManager, object $entity): array
    {
        if ($entityManager->getUnitOfWork()->isUninitializedObject($entity)) {
            return [];
        }

        $metadata = $entityManager->getClassMetadata($entity::class);
        $collections = [];
        foreach ($metadata->getAssociationNames() as $field) {
            if (!$metadata->isCollectionValuedAssociation($field)) {
                continue;
            }
            $value = $metadata->getFieldValue($entity, $field);
            if ($value instanceof PersistentCollection) {
                $collections[$field] = $value->isInitialized() ? \array_values($value->getSnapshot()) : null;
            }
        }

        return $collections;
    }
}
