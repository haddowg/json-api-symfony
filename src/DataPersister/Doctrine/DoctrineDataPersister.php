<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use haddowg\JsonApi\Collection\LinkRows\LinkRowWritePlan;
use haddowg\JsonApi\Exception\ClientGeneratedIdAlreadyExists;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Positions;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\MovedMember;
use haddowg\JsonApiBundle\DataPersister\PositionTargets;
use haddowg\JsonApiBundle\DataPersister\ReportsMovedMembersInterface;
use haddowg\JsonApiBundle\DataPersister\StoresDateTimesInterface;
use haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\WritesLinkRowsInterface;
use haddowg\JsonApiBundle\DataPersister\WritesPositionsInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineIdentifiers;
use haddowg\JsonApiBundle\DataProvider\Doctrine\NaiveDateTimeStorage;
use haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociation;
use haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Validation\RelationshipTypeUnacceptable;

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
 *
 * When the *related* type attaches an id encoder
 * ({@see \haddowg\JsonApi\Resource\Field\Id::encodeUsing()}) the linkage `id`s are
 * wire ids, so each is **decoded** to its storage key (via the injected
 * {@see IdEncoderResolver}, keyed by the related type) before `getReference` — the
 * wire-id SPI never changes, only this impl decodes. A related type
 * with no encoder decodes to itself, so the path is identical to today; a well-formed
 * but undecodable linkage id is a bad target — it raises {@see ResourceNotFound}
 * (`404`), rather than passing the raw wire string to `getReference` (which would
 * build a proxy that errors on initialization, surfacing as a `500`).
 */
final class DoctrineDataPersister implements DataPersisterInterface, TransactionalDataPersisterInterface, ReportsMovedMembersInterface, StoresDateTimesInterface, WritesLinkRowsInterface, WritesPositionsInterface
{
    private readonly DoctrineIdentifiers $ids;

    private readonly NaiveDateTimeStorage $dates;

    /**
     * @param array<string, class-string>    $entityClassByType a `type → entity FQCN` map
     * @param IdEncoderResolver               $idEncoders        resolves a related type's id encoder (linkage decode)
     * @param ?PivotAssociationResolver       $pivotAssociations resolves a belongsToMany pivot relation's association entity (always wired under Doctrine), for the writable-pivot association-entity diff
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
        private readonly IdEncoderResolver $idEncoders,
        private readonly ?PivotAssociationResolver $pivotAssociations = null,
    ) {
        $this->ids = new DoctrineIdentifiers($entityManager);
        $this->dates = new NaiveDateTimeStorage();
    }

    /**
     * Opens the `EntityManager`'s transaction. The existing per-operation flushes
     * in {@see create()}/{@see update()}/{@see delete()}/{@see mutateRelationship()}
     * then run INSIDE it: they are non-durable until {@see commit()} (or discarded by
     * {@see rollback()}), yet still materialise a store-generated id on the entity
     * immediately, so a later operation in the same batch can reference a
     * just-created resource's id (empirically confirmed against Doctrine + sqlite).
     */
    public function beginTransaction(): void
    {
        $this->entityManager->beginTransaction();
    }

    /**
     * Flushes any still-pending changes, then commits the transaction — durably
     * persisting every write the batch made since {@see beginTransaction()}.
     */
    public function commit(): void
    {
        $this->entityManager->flush();
        $this->entityManager->commit();
    }

    /**
     * Rolls the transaction back (discarding every buffered write) and clears the unit
     * of work, so the entities the rolled-back batch flushed are detached rather than
     * lingering in the identity map as phantom managed state. The manager is left
     * **open and reusable** — the request still has to render the error document, and a
     * long-running worker reuses the manager on its next message; closing it would
     * strand both (and forces a fragile reopen that breaks under older Doctrine ORM).
     *
     * GUARDED: a no-op when the manager is already closed or
     * has no active transaction. The atomic executor's commit-failure path rolls back
     * AFTER a failed commit (whose flush exception itself rolls back and closes the
     * manager), so an unconditional `rollback()` here would throw a secondary
     * `ConnectionException`/`EntityManagerClosed` that masks the original failure. The
     * guard makes the rollback idempotent so the real error surfaces.
     */
    public function rollback(): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }

        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->clear();
    }

    public function supports(string $type): bool
    {
        return isset($this->entityClassByType[$type]);
    }

    /**
     * Builds a new entity via Doctrine's constructor-less instantiation
     * ({@see \Doctrine\ORM\Mapping\ClassMetadata::newInstance()}) — the same
     * mechanism the ORM uses to hydrate entities on read, so the constructor is
     * **not** invoked. Entities with required constructor arguments therefore work
     * under the generic engine; constructor invariants/defaults do not run on
     * create (consistent with read-hydration). An application that needs them
     * overrides {@see instantiate()} via a custom {@see DataPersisterInterface}.
     */
    public function instantiate(string $type): object
    {
        return $this->entityManager->getClassMetadata($this->entityClassFor($type))->newInstance();
    }

    public function create(string $type, object $entity): object
    {
        // An id already on the new entity is a client id; one that is taken is a 409
        // rather than the database's unique-key error at flush.
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $ids = $metadata->getIdentifierValues($entity);
        $clientId = $ids !== [] && !\in_array(null, $ids, true);
        if ($clientId && $this->entityManager->find($metadata->getName(), $ids) !== null) {
            throw new ClientGeneratedIdAlreadyExists(self::describeId($ids));
        }

        $this->entityManager->persist($entity);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent create can take the id between the lookup above and the insert.
            if ($clientId && $this->lostTheRace($exception, $metadata, $ids)) {
                throw new ClientGeneratedIdAlreadyExists(self::describeId($ids));
            }

            throw $exception;
        }

        return $entity;
    }

    /**
     * Whether a unique violation came from inserting the new entity's own row under an id
     * another row now holds. The lookup reads through the connection, since the failed
     * flush closed the entity manager; a lookup the database refuses, as PostgreSQL does
     * inside a failed transaction, leaves the violation as it is.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $ids
     */
    private function lostTheRace(UniqueConstraintViolationException $exception, ClassMetadata $metadata, array $ids): bool
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quotes = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $table = $quotes->getTableName($metadata, $platform);

        $sql = $exception->getQuery()?->getSQL() ?? '';
        if (\preg_match('/^\s*INSERT\s+INTO\s+' . \preg_quote($table, '/') . '[\s(]/i', $sql) !== 1) {
            return false;
        }

        $conditions = [];
        $values = [];
        $types = [];
        foreach ($ids as $field => $value) {
            if ($metadata->hasAssociation($field)) {
                return false;
            }
            $conditions[] = $quotes->getColumnName($field, $metadata, $platform) . ' = ?';
            $values[] = $value;
            $types[] = $metadata->getTypeOfField($field) ?? 'string';
        }

        try {
            return $connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE %s', $table, \implode(' AND ', $conditions)), $values, $types) !== false;
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $ids
     */
    private static function describeId(array $ids): string
    {
        return \implode(' ', \array_map(static fn(mixed $id): string => \is_scalar($id) || $id instanceof \Stringable ? (string) $id : \get_debug_type($id), $ids));
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

        // A belongsToMany pivot relation is mutated as an association-entity DIFF, not
        // a plain collection write: the incoming linkage members carry pivot `meta`,
        // and the join row holding it is the auto-detected association entity. Upsert each
        // member's row (creating or reordering it in place from
        // the writable pivot fields), and on Replace drop the rows no longer present.
        if ($linkage instanceof ToManyRelationship
            && $this->pivotAssociations !== null
            && $this->pivotAssociations->isPivotRelation($relation)) {
            $relatedType = $relation->relatedTypes()[0] ?? '';
            $this->mutatePivot($entity, $relation, $relatedType, $this->entityClassFor($relatedType), $linkage, $mode);

            if ($flush) {
                $this->entityManager->flush();
            }

            return $entity;
        }

        if ($linkage instanceof ToOneRelationship) {
            $identifier = $linkage->resourceIdentifier;
            $this->property($entity, $property)->setValue($entity, $identifier?->id !== null
                ? $this->reference($relation, $identifier)
                : null);
        } else {
            $this->mutateToMany($entity, $property, $relation, $linkage, $mode);
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        return $entity;
    }

    /**
     * Whether linkage naming a related resource can be refused with a `404` by
     * {@see decodeLinkageId()}: a related type whose ids pass an encoder, or whose
     * identifier is not a plain string column, can be sent an id that is not one of
     * its own.
     */
    public function canMissLinkage(RelationInterface $relation): bool
    {
        foreach ($relation->relatedTypes() as $relatedType) {
            if (!isset($this->entityClassByType[$relatedType]) || $this->idEncoders->encoderFor($relatedType) !== null) {
                return true;
            }

            $metadata = $this->entityManager->getClassMetadata($this->entityClassByType[$relatedType]);
            $identifier = $metadata->getIdentifierFieldNames();
            if (\count($identifier) !== 1 || $metadata->hasAssociation($identifier[0])
                || !\in_array($metadata->getTypeOfField($identifier[0]), ['string', 'ascii_string', 'text'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a write to `$relation` of `$type` can be refused for leaving a member
     * without the parent its NOT NULL foreign key requires, as
     * {@see refuseOrphaningRequiredMembers()} decides from the mapping: an inverse,
     * non-pivot to-many with no `orphanRemoval` whose owning side has a non-nullable join
     * column.
     */
    public function canRefuseDetachment(string $type, RelationInterface $relation): bool
    {
        if (!$relation->isToMany() || ($this->pivotAssociations?->isPivotRelation($relation) ?? false)) {
            return false;
        }

        $metadata = $this->entityManager->getClassMetadata($this->entityClassFor($type));
        $property = $relation->column() ?? $relation->name();
        if (!$metadata->hasAssociation($property) || !$metadata->isAssociationInverseSide($property)
            || $this->mappingValue($metadata->getAssociationMapping($property), 'orphanRemoval') === true) {
            return false;
        }

        $owningField = $metadata->getAssociationMappedByTargetField($property);
        $target = $this->entityManager->getClassMetadata($metadata->getAssociationTargetClass($property));

        return $this->requiresParentOf($target, $owningField);
    }

    /**
     * Mutates a positioned to-many and writes its members' positions: on each pivot row
     * of a pivot relation, on each member otherwise. A parent already stored writes them
     * through {@see writePositions()}'s passes, so a unique owner and position key holds
     * throughout.
     */
    public function mutatePositioned(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToManyRelationship $linkage,
        Mode $mode,
        string $column,
        bool $flush = true,
    ): object {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $relatedClass = $this->entityClassFor($relatedType);

        if ($this->pivotAssociations !== null && $this->pivotAssociations->isPivotRelation($relation)) {
            $association = $this->pivotAssociations->resolve($relation, $entity, $relatedClass);
            $current = PositionTargets::current($this->existingPivotRows($entity, $association, $relatedClass), $column);
            $rows = $this->mutatePivot($entity, $relation, $relatedType, $relatedClass, $linkage, $mode);
        } else {
            $property = $relation->column() ?? $relation->name();
            $current = PositionTargets::current($this->membersByKey($entity, $property, $relatedClass), $column);
            $this->mutateToMany($entity, $property, $relation, $linkage, $mode);
            $rows = $this->membersByKey($entity, $property, $relatedClass);
        }

        $incoming = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            if ($identifier->id !== null && $identifier->type === $relatedType) {
                $incoming[] = $this->mapKey($relatedClass, $this->decodeLinkageId($relatedType, $identifier->id));
            }
        }

        $this->writePositions($entity, $current, PositionTargets::for($mode, $current, $incoming), $rows, $column, $flush);

        return $entity;
    }

    /**
     * Writes positions in core's {@see Positions::plan()} order, a flush after each
     * pass, so no two rows share an owner and position at any flush: the removed members
     * go and any new row is written at a temporary position past every one in use, then
     * the rows that move take their temporary positions, then everything takes its final
     * one. A parent not yet stored holds no positions to collide with, so it takes its
     * final positions in one write. The last pass is flushed only with `$flush`.
     */
    public function writePositions(object $parent, array $current, array $target, array $rows, string $column, bool $flush = true): void
    {
        $plan = Positions::plan($current, $target);

        if ($current !== [] && $this->entityManager->getUnitOfWork()->isInIdentityMap($parent)) {
            $offset = \max([-1, ...\array_values($current), ...\array_values($target)]) + 1;
            foreach ($plan->final as $key => $position) {
                if (!\array_key_exists($key, $current) && isset($rows[$key])) {
                    $this->property($rows[$key], $column)->setValue($rows[$key], $offset + $position);
                }
            }
            $this->entityManager->flush();

            if ($plan->temporary !== []) {
                foreach ($plan->temporary as $key => $position) {
                    if (isset($rows[$key])) {
                        $this->property($rows[$key], $column)->setValue($rows[$key], $position);
                    }
                }
                $this->entityManager->flush();
            }
        }

        foreach ($plan->final as $key => $position) {
            if (isset($rows[$key])) {
                $this->property($rows[$key], $column)->setValue($rows[$key], $position);
            }
        }

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    /**
     * The members of `$entity`'s to-many `$property`, keyed by their storage key.
     *
     * @param class-string $relatedClass
     *
     * @return array<string, object>
     */
    private function membersByKey(object $entity, string $property, string $relatedClass): array
    {
        $members = [];
        foreach ($this->toManyCollection($entity, $property)?->toArray() ?? [] as $member) {
            if (\is_object($member)) {
                $key = $this->ids->valueOf($this->entityManager->getClassMetadata($member::class), $member);
                if ($key !== null) {
                    $members[$this->mapKey($relatedClass, $key)] = $member;
                }
            }
        }

        return $members;
    }

    /**
     * Applies a declared `MorphToMany`'s link-row plan, flushing after each pass that
     * writes anything so no two rows share an owner and position at any flush: the
     * deletes, the temporary positions, the updates, then the inserts. A new row is a
     * `through()` entity, constructed when it can be so its defaults apply, with its owner
     * set through the association and each value converted to the field's storage form. A parent not yet stored has no rows to
     * delete or move, so its inserts wait for the caller's flush.
     */
    public function writeLinkRows(object $parent, MorphToMany $relation, LinkRowWritePlan $plan, bool $flush = true): object
    {
        $fields = $relation->linkRowFields()
            ?? throw new \LogicException(\sprintf('MorphToMany relation "%s" was not declared with identifiedByRows(), so it has no link rows to write.', $relation->name()));
        $metadata = $this->entityManager->getClassMetadata($fields->through);
        $rows = $this->toManyCollection($parent, $relation->column() ?? $relation->name());
        $stored = $this->entityManager->getUnitOfWork()->isInIdentityMap($parent);

        foreach ($plan->deletes as $deleted) {
            if (\is_object($deleted->row)) {
                $rows?->removeElement($deleted->row);
                $this->entityManager->remove($deleted->row);
            }
        }
        if ($plan->deletes !== [] && $stored) {
            $this->entityManager->flush();
        }

        $unions = [];
        foreach ($relation->pivotFields() as $pivotField) {
            if ($pivotField instanceof Union) {
                $unions[$pivotField->column() ?? $pivotField->name()] = $pivotField;
            }
        }

        foreach ([$plan->temporary, $plan->updates] as $pass) {
            foreach ($pass as $write) {
                if (\is_object($write->member->row)) {
                    $this->writeRowValues($metadata, $write->member->row, $write->values, $fields->owner, $parent, $unions);
                }
            }
            if ($pass !== [] && $stored) {
                $this->entityManager->flush();
            }
        }

        foreach ($plan->inserts as $insert) {
            $row = $this->newLinkRow($metadata);
            $this->writeRowValues($metadata, $row, $insert->values, $fields->owner, $parent, $unions);
            $rows?->add($row);
            $this->entityManager->persist($row);
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        return $parent;
    }

    /**
     * A new link row, constructed when its class takes no required argument so its
     * defaults apply, and otherwise created as Doctrine hydrates one.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function newLinkRow(ClassMetadata $metadata): object
    {
        $constructor = $metadata->getReflectionClass()->getConstructor();

        return $constructor === null || $constructor->getNumberOfRequiredParameters() === 0
            ? $metadata->getReflectionClass()->newInstance()
            : $metadata->newInstance();
    }

    /**
     * Sets a link row's fields, the owner to `$parent` through its association and every
     * other value converted to its column's PHP form and stored zone.
     *
     * A union pivot field's value goes in core's stored form where its column can't hold
     * a date-time.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $values
     * @param array<string, Union>  $unions   the relation's union pivot fields, by column
     */
    private function writeRowValues(ClassMetadata $metadata, object $row, array $values, string $ownerField, object $parent, array $unions = []): void
    {
        foreach ($values as $field => $value) {
            if ($field === $ownerField) {
                $metadata->setFieldValue($row, $field, $parent);

                continue;
            }

            if (isset($unions[$field]) && !$this->holdsDateTimes($row, $field)) {
                $metadata->setFieldValue($row, $field, $unions[$field]->toStoredForm($value));

                continue;
            }

            if ($metadata->hasField($field) && \is_scalar($value)) {
                $value = Type::getType($metadata->getTypeOfField($field) ?? 'string')
                    ->convertToPHPValue($value, $this->entityManager->getConnection()->getDatabasePlatform());
            }

            $metadata->setFieldValue($row, $field, $this->dateTimeForColumn($row, $field, $value));
        }
    }

    /**
     * Whether {@see movedMembers()} can report a move for `$relation` of `$type`: an
     * inverse, non-pivot to-many whose owning side is single-valued.
     */
    public function canMoveMembers(string $type, RelationInterface $relation): bool
    {
        if (!$relation->isToMany() || ($this->pivotAssociations?->isPivotRelation($relation) ?? false)) {
            return false;
        }

        $metadata = $this->entityManager->getClassMetadata($this->entityClassFor($type));
        $property = $relation->column() ?? $relation->name();
        $relatedType = $relation->relatedTypes()[0] ?? '';
        if (!$metadata->hasAssociation($property) || !$metadata->isAssociationInverseSide($property) || !isset($this->entityClassByType[$relatedType])) {
            return false;
        }

        return $this->entityManager->getClassMetadata($this->entityClassFor($relatedType))
            ->isSingleValuedAssociation($metadata->getAssociationMappedByTargetField($property));
    }

    /**
     * The members an add or replace on the inverse side of a one-to-many would take
     * from the parent they currently reference (or attach from no parent). A
     * many-to-many or pivot relation adds a member without taking it from anyone, so
     * it reports none.
     */
    public function movedMembers(string $type, object $parent, RelationInterface $relation, ToManyRelationship $linkage, Mode $mode): array
    {
        if ($mode === Mode::Remove
            || ($this->pivotAssociations !== null && $this->pivotAssociations->isPivotRelation($relation))) {
            return [];
        }

        $owningField = $this->inverseOwningField($parent, $relation->column() ?? $relation->name());
        $relatedType = $relation->relatedTypes()[0] ?? '';
        if ($owningField === null || !isset($this->entityClassByType[$relatedType])) {
            return [];
        }

        $relatedClass = $this->entityClassFor($relatedType);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);
        if (!$relatedMetadata->isSingleValuedAssociation($owningField)) {
            return [];
        }

        $moved = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            if ($identifier->id === null || $this->relatedTypeOf($relation, $identifier) !== $relatedType) {
                continue;
            }

            $member = $this->entityManager->find($relatedClass, $this->decodeLinkageId($relatedType, $identifier->id));
            if ($member === null) {
                continue;
            }

            $from = $relatedMetadata->getFieldValue($member, $owningField);
            if ($from === $parent) {
                continue;
            }

            $moved[] = new MovedMember($member, \is_object($from) ? $from : null);
        }

        return $moved;
    }

    private function mutateToMany(
        object $entity,
        string $property,
        RelationInterface $relation,
        ToManyRelationship $linkage,
        Mode $mode,
    ): void {
        $collection = $this->toManyCollection($entity, $property);
        if (!$collection instanceof Collection) {
            return;
        }

        $owningField = $this->inverseOwningField($entity, $property);

        $incoming = \array_values(\array_filter(
            $linkage->resourceIdentifiers,
            static fn(ResourceIdentifier $identifier): bool => $identifier->id !== null,
        ));

        $this->refuseOrphaningRequiredMembers($entity, $property, $collection, $relation, $owningField, $incoming, $mode);

        if ($mode === Mode::Replace) {
            // Detach the existing members (clear the owning-side FK / drop the join
            // row on each) then rebuild the collection from the incoming references.
            foreach ($collection->toArray() as $member) {
                if (\is_object($member)) {
                    $this->detachOwner($member, $owningField, $entity);
                }
            }
            $collection->clear();
            foreach ($incoming as $identifier) {
                $this->addMember($collection, $relation, $identifier, $entity, $owningField);
            }

            return;
        }

        if ($mode === Mode::Add) {
            foreach ($incoming as $identifier) {
                $this->addMember($collection, $relation, $identifier, $entity, $owningField);
            }

            return;
        }

        // Mode::Remove — drop the incoming members and clear their owning side
        // (the owning-side FK for a single-valued inverse, or the join row for a
        // many-to-many inverse). A resource that is not a member is left alone: it may
        // belong to another parent, and removing a non-member is a no-op.
        foreach ($incoming as $identifier) {
            $reference = $this->reference($relation, $identifier);
            if ($reference === null || !$collection->contains($reference)) {
                continue;
            }
            $collection->removeElement($reference);
            $this->detachOwner($reference, $owningField, $entity);
        }
    }

    /**
     * The association-entity DIFF — the reorder engine for a writable-pivot
     * `belongsToMany`. Resolves the auto-detected association entity
     * and, against the parent's existing join rows keyed by far member:
     *  - {@see Mode::Replace} — UPSERT every incoming member (update an existing row's
     *    writable pivot fields from `meta` IN PLACE — the reorder — or create a new
     *    row), then REMOVE the rows whose member is not in the incoming set (full sync);
     *  - {@see Mode::Add} — upsert the incoming members, leave the rest;
     *  - {@see Mode::Remove} — remove the incoming members' rows (a remove carries no
     *    pivot meta).
     *
     * A readOnly pivot field is NEVER written from `meta`; on a freshly-created row it
     * takes its server-owned value (the association entity's own default / a
     * `PrePersist` callback). The association entities are managed (persisted /
     * removed here), so the flush the caller controls is storage-correct. Returns the
     * rows the parent holds afterwards, keyed by far member.
     *
     * @param class-string $relatedClass the far (related) entity class
     *
     * @return array<string, object>
     */
    private function mutatePivot(
        object $parent,
        RelationInterface $relation,
        string $relatedType,
        string $relatedClass,
        ToManyRelationship $linkage,
        Mode $mode,
    ): array {
        \assert($relation instanceof BelongsToMany);
        \assert($this->pivotAssociations !== null);

        $association = $this->pivotAssociations->resolve($relation, $parent, $relatedClass);
        $existing = $this->existingPivotRows($parent, $association, $relatedClass);

        $incomingMembers = $this->incomingPivotMembers($relation, $linkage, $relatedType, $relatedClass);

        if ($mode === Mode::Remove) {
            foreach ($incomingMembers as [$storageKey]) {
                $mapKey = $this->mapKey($relatedClass, $storageKey);
                $row = $existing[$mapKey] ?? null;
                if ($row !== null) {
                    $this->removePivotRow($parent, $association, $row);
                    unset($existing[$mapKey]);
                }
            }

            return $existing;
        }

        $kept = [];
        foreach ($incomingMembers as [$storageKey, $reference, $meta]) {
            $mapKey = $this->mapKey($relatedClass, $storageKey);

            $row = $existing[$mapKey] ?? null;
            if ($row !== null) {
                // Reorder / update an existing row's writable pivot fields IN PLACE
                // (update context — the row exists).
                $this->writePivotFields($row, $relation->writablePivotFields(false), $meta);
                $kept[$mapKey] = $row;

                continue;
            }

            // A new association row: parent + far reference + the writable pivot
            // fields (create context); readOnly fields take their server default.
            $row = $this->newPivotRow($association, $parent, $reference);
            $this->writePivotFields($row, $relation->writablePivotFields(true), $meta);
            $this->attachPivotRow($parent, $association, $row);
            $this->entityManager->persist($row);
            $kept[$mapKey] = $row;
        }

        if ($mode === Mode::Replace) {
            foreach ($existing as $mapKey => $row) {
                if (!isset($kept[$mapKey])) {
                    $this->removePivotRow($parent, $association, $row);
                }
            }

            return $kept;
        }

        return $kept + $existing;
    }

    /**
     * The parent's existing association rows keyed by their far member's storage key
     * (its scalar identifier), read off the parent's inverse `OneToMany` collection
     * when mapped, else queried from the association repository by the parent. Where
     * the same far member appears more than once (duplicate membership) the last row
     * wins the key — pivot meta is a single per-member value set.
     *
     * @param class-string $relatedClass
     *
     * @return array<string, object>
     */
    private function existingPivotRows(object $parent, PivotAssociation $association, string $relatedClass): array
    {
        $rows = $this->parentInverseCollection($parent, $association);
        if ($rows === null) {
            // No mapped inverse collection (or the parent is unflushed with none): query
            // the association rows by parent — a managed parent finds its committed rows.
            $rows = $this->entityManager
                ->getRepository($association->entityClass)
                ->findBy([$association->parentProperty => $parent]);
        }

        $byFar = [];
        foreach ($rows as $row) {
            if (!\is_object($row)) {
                continue;
            }
            $far = $this->readProperty($row, $association->farProperty);
            if (!\is_object($far)) {
                continue;
            }
            $storageKey = $this->ids->valueOf($this->entityManager->getClassMetadata($far::class), $far);
            if ($storageKey !== null) {
                $byFar[$this->mapKey($relatedClass, $storageKey)] = $row;
            }
        }

        return $byFar;
    }

    /**
     * The parent's inverse `OneToMany` collection of the association entity (the
     * collection `mappedBy` the association's parent-side `ManyToOne`), as a list — or
     * `null` when the parent maps no such inverse collection (then the rows are queried).
     *
     * @return list<object>|null
     */
    private function parentInverseCollection(object $parent, PivotAssociation $association): ?array
    {
        $metadata = $this->entityManager->getClassMetadata($parent::class);

        foreach ($metadata->getAssociationNames() as $field) {
            if (!$metadata->isCollectionValuedAssociation($field)) {
                continue;
            }
            if ($this->entityManager->getClassMetadata($metadata->getAssociationTargetClass($field))->getName() !== $this->entityManager->getClassMetadata($association->entityClass)->getName()) {
                continue;
            }
            if (!$this->isInverseOf($metadata, $field, $association->parentProperty)) {
                continue;
            }

            $value = $this->readProperty($parent, $field);

            return \is_iterable($value)
                ? \array_values(\array_filter([...$value], '\is_object'))
                : [];
        }

        return null;
    }

    /**
     * Builds the parsed incoming members: each a `[farStorageKey, managedFarReference,
     * meta]` triple. A linkage id with no resolvable storage key (an undecodable wire
     * id) raises {@see ResourceNotFound}, exactly as the plain mutation path does.
     *
     * @param class-string $relatedClass
     *
     * @return list<array{0: mixed, 1: object, 2: array<string, mixed>}>
     */
    private function incomingPivotMembers(RelationInterface $relation, ToManyRelationship $linkage, string $relatedType, string $relatedClass): array
    {
        $members = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            if ($identifier->id === null) {
                continue;
            }
            $this->relatedTypeOf($relation, $identifier);
            $storageKey = $this->decodeLinkageId($relatedType, $identifier->id);
            $reference = $this->entityManager->getReference($relatedClass, $storageKey);
            if ($reference === null) {
                continue;
            }
            // Writable pivot values are nested under the linkage member's `meta.pivot`
            // (symmetric with how pivot RENDERS on reads, and how the OpenAPI request
            // schema types it) — not directly under `meta`.
            $pivotMeta = $identifier->meta['pivot'] ?? [];
            $members[] = [$storageKey, $reference, \is_array($pivotMeta) ? $pivotMeta : []];
        }

        return $members;
    }

    /**
     * Sets each writable pivot field's column on the association row from the linkage
     * `meta`, coercing the wire value through the field's own cast. A field absent
     * from `meta` is left untouched (its current value on an update; its server
     * default on a create). A readOnly field is never in `$fields`, so it is never
     * written from `meta`.
     *
     * @param list<FieldInterface> $fields the writable pivot fields for the context
     * @param array<string, mixed> $meta   the linkage member's pivot meta
     */
    private function writePivotFields(object $row, array $fields, array $meta): void
    {
        foreach ($fields as $field) {
            if (!\array_key_exists($field->name(), $meta)) {
                continue;
            }

            $column = $field->column() ?? $field->name();
            $value = $this->coercePivotValue($field, $meta[$field->name()]);
            if ($field instanceof Union && !$this->holdsDateTimes($row, $column)) {
                $value = $field->toStoredForm($value);
            }
            $this->property($row, $column)->setValue($row, $this->dateTimeForColumn($row, $column, $value));
        }
    }

    /**
     * A client date-time written to `$column` of `$entity`, moved into the zone a naive
     * `datetime` column is stored in, so a value sent with an offset is persisted as the
     * right local time, and given the date class the column's type converts. Any other
     * value, or a column that is not naive, keeps its zone.
     */
    public function dateTimeForColumn(object $entity, string $column, mixed $value): mixed
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        if (!$metadata->hasField($column)) {
            return $value;
        }

        $type = $metadata->getTypeOfField($column);

        return $this->dates->asColumnClass($this->dates->toStorage($value, $type), $type);
    }

    public function holdsDateTimes(object $entity, string $column): bool
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        if (!$metadata->hasField($column)) {
            return true;
        }

        $type = $metadata->getTypeOfField($column);

        return !($type !== null && Type::hasType($type) && Type::getType($type) instanceof JsonType);
    }

    /**
     * Coerces a wire pivot value to its domain representation via the field's OWN
     * value cast (an `Integer` → int, a `DateTime` → `\DateTimeImmutable`), so the
     * association entity's typed column receives the right type. A pivot field is a
     * plain field definition (no `deserializeUsing`/`fillUsing` hook), so only its
     * declared type's value cast applies, request-independently, through the field's
     * public {@see FieldInterface::castWireValue()} seam.
     */
    private function coercePivotValue(FieldInterface $field, mixed $value): mixed
    {
        return $field->castWireValue($value);
    }

    /**
     * A new, unpopulated association-entity instance via Doctrine's constructor-less
     * instantiation, with its parent and far references set. The pivot
     * fields are written separately; readOnly fields take their server default (the
     * entity's own column default or a `PrePersist` callback).
     */
    private function newPivotRow(PivotAssociation $association, object $parent, object $reference): object
    {
        $row = $this->entityManager->getClassMetadata($association->entityClass)->newInstance();
        $this->property($row, $association->parentProperty)->setValue($row, $parent);
        $this->property($row, $association->farProperty)->setValue($row, $reference);

        return $row;
    }

    /**
     * Adds a new association row to the parent's inverse collection when one is mapped
     * and initialised, so the in-memory object graph reflects the new membership
     * immediately (the owning `ManyToOne` to the parent, already set, carries the FK on
     * flush regardless).
     */
    private function attachPivotRow(object $parent, PivotAssociation $association, object $row): void
    {
        $field = $this->parentInverseField($parent, $association);
        if ($field === null) {
            return;
        }

        $collection = $this->toManyCollection($parent, $field);
        if ($collection !== null && !$collection->contains($row)) {
            $collection->add($row);
        }
    }

    /**
     * Removes an association row: drops it from the parent's inverse collection (when
     * mapped) and removes the entity, so the join row is deleted on flush.
     */
    private function removePivotRow(object $parent, PivotAssociation $association, object $row): void
    {
        $field = $this->parentInverseField($parent, $association);
        if ($field !== null) {
            $collection = $this->readProperty($parent, $field);
            if ($collection instanceof Collection) {
                $collection->removeElement($row);
            }
        }

        $this->entityManager->remove($row);
    }

    /**
     * The parent's inverse `OneToMany` field name for the association entity (the
     * collection `mappedBy` the association's parent-side `ManyToOne`), or `null` when
     * the parent maps no such inverse collection.
     */
    private function parentInverseField(object $parent, PivotAssociation $association): ?string
    {
        $metadata = $this->entityManager->getClassMetadata($parent::class);
        $entityName = $this->entityManager->getClassMetadata($association->entityClass)->getName();

        foreach ($metadata->getAssociationNames() as $field) {
            if (!$metadata->isCollectionValuedAssociation($field)) {
                continue;
            }
            if ($this->entityManager->getClassMetadata($metadata->getAssociationTargetClass($field))->getName() !== $entityName) {
                continue;
            }
            if ($this->isInverseOf($metadata, $field, $association->parentProperty)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The stable string map key for a far member's storage key, the same for every form
     * of one id ({@see DoctrineIdentifiers::key()}), so existing rows and incoming
     * members pair on the same far member. An id its type cannot key falls back to its
     * object hash.
     *
     * @param class-string $relatedClass
     */
    private function mapKey(string $relatedClass, mixed $storageKey): string
    {
        $key = $this->ids->key($this->entityManager->getClassMetadata($relatedClass), $storageKey);
        if ($key !== null) {
            return $key;
        }

        return \is_object($storageKey) ? \spl_object_hash($storageKey) : \serialize($storageKey);
    }

    /**
     * Resolves a managed reference for `$id` and adds it to the collection
     * (idempotent — a member already present is not duplicated), then sets the
     * owning side on it. A no-op when no reference resolves.
     *
     * @param Collection<int, object> $collection
     */
    private function addMember(
        Collection $collection,
        RelationInterface $relation,
        ResourceIdentifier $identifier,
        object $owner,
        ?string $owningField,
    ): void {
        $reference = $this->reference($relation, $identifier);
        if ($reference === null) {
            return;
        }

        if (!$collection->contains($reference)) {
            $collection->add($reference);
        }
        $this->attachOwner($reference, $owningField, $owner);
    }

    /**
     * The owning-side field on the related entity for an inverse-side association,
     * or `null` when the parent's association is itself the owning side (so the
     * collection write alone carries the foreign key).
     */
    private function inverseOwningField(object $entity, string $property): ?string
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        if (!$metadata->isAssociationInverseSide($property)) {
            return null;
        }

        return $metadata->getAssociationMappedByTargetField($property);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function isInverseOf(ClassMetadata $metadata, string $field, string $owningField): bool
    {
        return $metadata->isAssociationInverseSide($field)
            && $metadata->getAssociationMappedByTargetField($field) === $owningField;
    }

    /**
     * Attaches `$owner` to the related `$member`'s owning side, so the association
     * persists from the side Doctrine tracks. A no-op when the parent side already
     * owns the foreign key (`$owningField === null`). When the owning side is a
     * single-valued inverse (a `OneToMany`'s `ManyToOne` owner) it sets the
     * reference; when it is a many-valued inverse (a `ManyToMany`'s owning
     * collection) it adds `$owner` to that collection (idempotently) — the latter
     * is what makes mutating the inverse side of a many-to-many persist the join
     * row instead of assigning a single object to a `Collection` property (a 500).
     */
    private function attachOwner(object $member, ?string $owningField, object $owner): void
    {
        if ($owningField === null) {
            return;
        }

        if ($this->isOwningSideCollection($member, $owningField)) {
            $collection = $this->toManyCollection($member, $owningField);
            if ($collection !== null && !$collection->contains($owner)) {
                $collection->add($owner);
            }

            return;
        }

        $this->property($member, $owningField)->setValue($member, $owner);
    }

    /**
     * Refuses a remove, or a replace that drops a current member, when a dropped member's
     * foreign key to the parent is NOT NULL: the member can't be left without a parent,
     * so the write is a 403 rather than a constraint error at flush. An inverse side that
     * declares `orphanRemoval` deletes the member instead, so it is allowed.
     *
     * @param Collection<int, object>  $collection
     * @param list<ResourceIdentifier>      $incoming
     */
    private function refuseOrphaningRequiredMembers(
        object $entity,
        string $property,
        Collection $collection,
        RelationInterface $relation,
        ?string $owningField,
        array $incoming,
        Mode $mode,
    ): void {
        if ($owningField === null || $mode === Mode::Add
            || $this->mappingValue($this->entityManager->getClassMetadata($entity::class)->getAssociationMapping($property), 'orphanRemoval') === true) {
            return;
        }

        $named = [];
        foreach ($incoming as $identifier) {
            $reference = $this->reference($relation, $identifier);
            if ($reference !== null) {
                $named[\spl_object_id($reference)] = $reference;
            }
        }

        $dropped = $mode === Mode::Remove
            ? \array_filter($named, $collection->contains(...))
            : \array_filter($collection->toArray(), static fn(mixed $member): bool => \is_object($member) && !isset($named[\spl_object_id($member)]));

        foreach ($dropped as $member) {
            if (\is_object($member) && $this->requiresParent($member, $owningField)) {
                throw $mode === Mode::Remove
                    ? new RemovalProhibited($relation->name())
                    : new FullReplacementProhibited($relation->name());
            }
        }
    }

    /**
     * Whether `$member`'s single-valued owning field to its parent has a NOT NULL join
     * column.
     */
    private function requiresParent(object $member, string $owningField): bool
    {
        return $this->requiresParentOf($this->entityManager->getClassMetadata($member::class), $owningField);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function requiresParentOf(ClassMetadata $metadata, string $owningField): bool
    {
        if (!$metadata->hasAssociation($owningField) || $metadata->isCollectionValuedAssociation($owningField)) {
            return false;
        }

        $joinColumns = $this->mappingValue($metadata->getAssociationMapping($owningField), 'joinColumns');
        foreach (\is_iterable($joinColumns) ? $joinColumns : [] as $joinColumn) {
            if ($this->mappingValue($joinColumn, 'nullable') === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads `$key` off a Doctrine mapping, which is an array on ORM 2 and an object on
     * ORM 3.
     */
    private function mappingValue(mixed $mapping, string $key): mixed
    {
        if (\is_array($mapping)) {
            return $mapping[$key] ?? null;
        }

        return \is_object($mapping) && \property_exists($mapping, $key) ? $mapping->{$key} : null;
    }

    /**
     * Detaches `$owner` from the related `$member`'s owning side: clears the
     * single-valued owning reference (a `OneToMany` inverse), or removes `$owner`
     * from the owning collection (a `ManyToMany` inverse, dropping the join row).
     * A no-op when the parent side owns the foreign key (`$owningField === null`).
     */
    private function detachOwner(object $member, ?string $owningField, object $owner): void
    {
        if ($owningField === null) {
            return;
        }

        if ($this->isOwningSideCollection($member, $owningField)) {
            $this->toManyCollection($member, $owningField)?->removeElement($owner);

            return;
        }

        $this->property($member, $owningField)->setValue($member, null);
    }

    /**
     * Whether the related entity's owning-side association `$owningField` is
     * many-valued (a `ManyToMany` owning collection) rather than single-valued (a
     * `ManyToOne`), so the owning side is written by collection mutation, not assignment.
     */
    private function isOwningSideCollection(object $member, string $owningField): bool
    {
        $metadata = $this->entityManager->getClassMetadata($member::class);

        return $metadata->hasAssociation($owningField)
            && $metadata->isCollectionValuedAssociation($owningField);
    }

    /**
     * The entity's to-many association collection, initialising an uninitialised
     * typed property to an empty {@see ArrayCollection} first.
     *
     * On create the persister instantiates the entity without invoking its
     * constructor (ADR 0018), so an association collection a constructor would have
     * initialised is left uninitialised — reading it directly would
     * {@see \Error} ("accessed before initialization"). Initialising it here
     * mirrors how Doctrine populates association collections on read-hydration, so
     * relationships in a whole-resource create write apply against a real
     * collection. Returns `null` when the property is not a collection at all.
     *
     * @return Collection<int, object>|null
     */
    private function toManyCollection(object $entity, string $property): ?Collection
    {
        $reflection = $this->property($entity, $property);

        if (!$reflection->isInitialized($entity)) {
            $collection = new ArrayCollection();
            $reflection->setValue($entity, $collection);

            return $collection;
        }

        $value = $reflection->getValue($entity);

        return $value instanceof Collection ? $value : null;
    }

    /**
     * The property's value, or null while a typed property is uninitialised.
     */
    private function readProperty(object $entity, string $property): mixed
    {
        $reflection = $this->property($entity, $property);

        return $reflection->isInitialized($entity) ? $reflection->getValue($entity) : null;
    }

    /**
     * Reflection on the entity's property, so a private or protected property, one
     * declared on a parent class included, is read and written the way Doctrine
     * hydrates it rather than through public property access.
     */
    private function property(object $entity, string $property): \ReflectionProperty
    {
        // Reflection does not load a lazy proxy, so an unloaded one would read as
        // uninitialised and have its collection replaced.
        $this->entityManager->initializeObject($entity);
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        // getReflectionProperty() covers the whole supported doctrine/orm range; see
        // DoctrineRelationshipLoadState.
        if ($metadata->hasField($property) || $metadata->hasAssociation($property)) {
            $reflection = $metadata->getReflectionProperty($property);
            if ($reflection !== null) {
                return $reflection;
            }
        }

        for ($class = new \ReflectionClass($entity); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($property)) {
                return $class->getProperty($property);
            }
        }

        return new \ReflectionProperty($entity, $property);
    }

    /**
     * A managed reference to the resource `$identifier` names, resolved under the
     * identifier's own type.
     */
    private function reference(RelationInterface $relation, ResourceIdentifier $identifier): ?object
    {
        $type = $this->relatedTypeOf($relation, $identifier);

        return $this->entityManager->getReference($this->entityClassFor($type), $this->decodeLinkageId($type, (string) $identifier->id));
    }

    /**
     * The identifier's type, which must be one of the relation's related types.
     *
     * @throws RelationshipTypeUnacceptable
     */
    private function relatedTypeOf(RelationInterface $relation, ResourceIdentifier $identifier): string
    {
        if (!\in_array($identifier->type, $relation->relatedTypes(), true)) {
            throw new RelationshipTypeUnacceptable([RelationshipTypeUnacceptable::error($relation, $identifier->type)]);
        }

        return $identifier->type;
    }

    /**
     * Decodes a linkage wire id to the related type's storage key when that type
     * declares an id encoder (otherwise wire == storage), then converts it through the
     * id's Doctrine type to the form the entity holds, which `getReference` needs: a
     * `uuid` id becomes its Uuid object. A wire id that does not decode or convert has
     * no storage key, so no entity holds it: it is a bad linkage target and raises
     * {@see ResourceNotFound} (`404`), rather than reaching `getReference`, whose proxy
     * would error on initialization and surface as a `500`.
     */
    private function decodeLinkageId(string $relatedType, string $id): mixed
    {
        $encoder = $this->idEncoders->encoderFor($relatedType);
        $storageKey = $encoder !== null ? $encoder->decode($id) : $id;

        return $this->ids->toPhp($this->entityManager->getClassMetadata($this->entityClassFor($relatedType)), $storageKey)
            ?? throw new ResourceNotFound();
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
