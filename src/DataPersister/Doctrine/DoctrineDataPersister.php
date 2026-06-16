<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\AbstractField;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociation;
use haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;

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
 * wire-id SPI never changes, only this impl decodes (bundle ADR 0038). A related type
 * with no encoder decodes to itself, so the path is identical to today; a well-formed
 * but undecodable linkage id is a bad target — it raises {@see ResourceNotFound}
 * (`404`), rather than passing the raw wire string to `getReference` (which would
 * build a proxy that errors on initialization, surfacing as a `500`).
 */
final class DoctrineDataPersister implements DataPersisterInterface
{
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
    ) {}

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

        // A belongsToMany pivot relation is mutated as an association-entity DIFF, not
        // a plain collection write: the incoming linkage members carry pivot `meta`,
        // and the join row holding it is the auto-detected association entity (bundle
        // ADR 0045). Upsert each member's row (creating or reordering it in place from
        // the writable pivot fields), and on Replace drop the rows no longer present.
        if ($linkage instanceof ToManyRelationship
            && $this->pivotAssociations !== null
            && $this->pivotAssociations->isPivotRelation($relation)) {
            $this->mutatePivot($entity, $relation, $relatedType, $relatedClass, $linkage, $mode);

            if ($flush) {
                $this->entityManager->flush();
            }

            return $entity;
        }

        if ($linkage instanceof ToOneRelationship) {
            $reference = $linkage->resourceIdentifier?->id !== null
                ? $this->entityManager->getReference($relatedClass, $this->decodeLinkageId($relatedType, $linkage->resourceIdentifier->id))
                : null;

            $entity->{$property} = $reference;
        } else {
            $this->mutateToMany($entity, $property, $relatedType, $relatedClass, $linkage, $mode);
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
        string $relatedType,
        string $relatedClass,
        ToManyRelationship $linkage,
        Mode $mode,
    ): void {
        $collection = $this->toManyCollection($entity, $property);
        if (!$collection instanceof Collection) {
            return;
        }

        $owningField = $this->inverseOwningField($entity, $property);

        $incomingIds = \array_values(\array_filter(
            $linkage->getResourceIdentifierIds(),
            static fn(?string $id): bool => $id !== null,
        ));

        if ($mode === Mode::Replace) {
            // Detach the existing members (clear the owning-side FK / drop the join
            // row on each) then rebuild the collection from the incoming references.
            foreach ($collection->toArray() as $member) {
                if (\is_object($member)) {
                    $this->detachOwner($member, $owningField, $entity);
                }
            }
            $collection->clear();
            foreach ($incomingIds as $id) {
                $this->addMember($collection, $relatedType, $relatedClass, $id, $entity, $owningField);
            }

            return;
        }

        if ($mode === Mode::Add) {
            foreach ($incomingIds as $id) {
                $this->addMember($collection, $relatedType, $relatedClass, $id, $entity, $owningField);
            }

            return;
        }

        // Mode::Remove — drop the incoming members and clear their owning side
        // (the owning-side FK for a single-valued inverse, or the join row for a
        // many-to-many inverse).
        foreach ($incomingIds as $id) {
            $reference = $this->entityManager->getReference($relatedClass, $this->decodeLinkageId($relatedType, $id));
            if ($reference === null) {
                continue;
            }
            $collection->removeElement($reference);
            $this->detachOwner($reference, $owningField, $entity);
        }
    }

    /**
     * The association-entity DIFF — the reorder engine for a writable-pivot
     * `belongsToMany`. Resolves the auto-detected association entity (bundle ADR 0045)
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
     * removed here), so the flush the caller controls is storage-correct.
     *
     * @param class-string $relatedClass the far (related) entity class
     */
    private function mutatePivot(
        object $parent,
        RelationInterface $relation,
        string $relatedType,
        string $relatedClass,
        ToManyRelationship $linkage,
        Mode $mode,
    ): void {
        \assert($relation instanceof BelongsToMany);
        \assert($this->pivotAssociations !== null);

        $association = $this->pivotAssociations->resolve($relation, $parent, $relatedClass);
        $existing = $this->existingPivotRows($parent, $association, $relatedClass);

        $incomingMembers = $this->incomingPivotMembers($linkage, $relatedType, $relatedClass);

        if ($mode === Mode::Remove) {
            foreach ($incomingMembers as [$storageKey]) {
                $row = $existing[$this->mapKey($storageKey)] ?? null;
                if ($row !== null) {
                    $this->removePivotRow($parent, $association, $row);
                }
            }

            return;
        }

        $keptKeys = [];
        foreach ($incomingMembers as [$storageKey, $reference, $meta]) {
            $mapKey = $this->mapKey($storageKey);
            $keptKeys[$mapKey] = true;

            $row = $existing[$mapKey] ?? null;
            if ($row !== null) {
                // Reorder / update an existing row's writable pivot fields IN PLACE
                // (update context — the row exists).
                $this->writePivotFields($row, $relation->writablePivotFields(false), $meta);

                continue;
            }

            // A new association row: parent + far reference + the writable pivot
            // fields (create context); readOnly fields take their server default.
            $row = $this->newPivotRow($association, $parent, $reference);
            $this->writePivotFields($row, $relation->writablePivotFields(true), $meta);
            $this->attachPivotRow($parent, $association, $row);
            $this->entityManager->persist($row);
        }

        if ($mode === Mode::Replace) {
            foreach ($existing as $mapKey => $row) {
                if (!isset($keptKeys[$mapKey])) {
                    $this->removePivotRow($parent, $association, $row);
                }
            }
        }
    }

    /**
     * The parent's existing association rows keyed by their far member's storage key
     * (its scalar identifier), read off the parent's inverse `OneToMany` collection
     * when mapped, else queried from the association repository by the parent. Where
     * the same far member appears more than once (duplicate membership) the last row
     * wins the key — pivot meta is a single per-member value set (ADR 0045).
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

        $farIdField = $this->entityManager->getClassMetadata($relatedClass)->getSingleIdentifierFieldName();

        $byFar = [];
        foreach ($rows as $row) {
            if (!\is_object($row)) {
                continue;
            }
            $far = $row->{$association->farProperty} ?? null;
            if (!\is_object($far)) {
                continue;
            }
            $storageKey = $this->entityManager->getClassMetadata($far::class)->getIdentifierValues($far)[$farIdField] ?? null;
            if ($storageKey !== null) {
                $byFar[$this->mapKey($storageKey)] = $row;
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

        foreach ($metadata->getAssociationMappings() as $field => $mapping) {
            $field = (string) $field;
            if (!$metadata->isCollectionValuedAssociation($field)) {
                continue;
            }
            if ($this->entityManager->getClassMetadata($mapping->targetEntity)->getName() !== $this->entityManager->getClassMetadata($association->entityClass)->getName()) {
                continue;
            }
            if (($mapping['mappedBy'] ?? null) !== $association->parentProperty) {
                continue;
            }

            $value = (new \ReflectionProperty($parent, $field))->isInitialized($parent) ? ($parent->{$field} ?? null) : null;

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
    private function incomingPivotMembers(ToManyRelationship $linkage, string $relatedType, string $relatedClass): array
    {
        $members = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            if ($identifier->id === null) {
                continue;
            }
            $storageKey = $this->decodeLinkageId($relatedType, $identifier->id);
            $reference = $this->entityManager->getReference($relatedClass, $storageKey);
            if ($reference === null) {
                continue;
            }
            $members[] = [$storageKey, $reference, $identifier->meta];
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
            $row->{$column} = $this->coercePivotValue($field, $meta[$field->name()]);
        }
    }

    /**
     * Coerces a wire pivot value to its domain representation via the field's OWN
     * value cast (an `Integer` → int, a `DateTime` → `\DateTimeImmutable`), so the
     * association entity's typed column receives the right type. A pivot field is a
     * plain field definition (no `deserializeUsing`/`fillUsing` hook), whose cast is
     * the field's protected `deserializeValue`; it is request-independent, so a
     * closure bound to the {@see AbstractField} base invokes it without building a
     * request. A field not built on that base passes the value through unchanged.
     */
    private function coercePivotValue(FieldInterface $field, mixed $value): mixed
    {
        if (!$field instanceof AbstractField) {
            return $value;
        }

        /** @var \Closure(mixed): mixed $cast */
        $cast = \Closure::bind(
            fn(mixed $raw): mixed => $this->deserializeValue($raw),
            $field,
            AbstractField::class,
        );

        return $cast($value);
    }

    /**
     * A new, unpopulated association-entity instance via Doctrine's constructor-less
     * instantiation (ADR 0029), with its parent and far references set. The pivot
     * fields are written separately; readOnly fields take their server default (the
     * entity's own column default or a `PrePersist` callback).
     */
    private function newPivotRow(PivotAssociation $association, object $parent, object $reference): object
    {
        $row = $this->entityManager->getClassMetadata($association->entityClass)->newInstance();
        $row->{$association->parentProperty} = $parent;
        $row->{$association->farProperty} = $reference;

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

        $reflection = new \ReflectionProperty($parent, $field);
        if (!$reflection->isInitialized($parent)) {
            $parent->{$field} = new ArrayCollection();
        }

        $collection = $parent->{$field} ?? null;
        if ($collection instanceof Collection && !$collection->contains($row)) {
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
        if ($field !== null && (new \ReflectionProperty($parent, $field))->isInitialized($parent)) {
            $collection = $parent->{$field} ?? null;
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

        foreach ($metadata->getAssociationMappings() as $field => $mapping) {
            $field = (string) $field;
            if (!$metadata->isCollectionValuedAssociation($field)) {
                continue;
            }
            if ($this->entityManager->getClassMetadata($mapping->targetEntity)->getName() !== $entityName) {
                continue;
            }
            if (($mapping['mappedBy'] ?? null) === $association->parentProperty) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The stable string map key for a far member's storage key (scalar id → its string
     * form; a non-scalar id → its object hash), so existing rows and incoming members
     * pair on the same far member.
     */
    private function mapKey(mixed $storageKey): string
    {
        if (\is_scalar($storageKey)) {
            return (string) $storageKey;
        }

        return \is_object($storageKey) ? \spl_object_hash($storageKey) : \serialize($storageKey);
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
        string $relatedType,
        string $relatedClass,
        string $id,
        object $owner,
        ?string $owningField,
    ): void {
        $reference = $this->entityManager->getReference($relatedClass, $this->decodeLinkageId($relatedType, $id));
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

        $member->{$owningField} = $owner;
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

        $member->{$owningField} = null;
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
     * constructor (ADR 0029), so an association collection a constructor would have
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
        $reflection = new \ReflectionProperty($entity, $property);

        if (!$reflection->isInitialized($entity)) {
            $collection = new ArrayCollection();
            $entity->{$property} = $collection;

            return $collection;
        }

        $value = $entity->{$property};

        return $value instanceof Collection ? $value : null;
    }

    /**
     * Decodes a linkage wire id to the related type's storage key when that type
     * declares an id encoder; otherwise (no encoder, wire == storage) returns it
     * unchanged. A well-formed but undecodable wire id has no storage key — no entity
     * holds it — so it is a bad linkage target: it raises {@see ResourceNotFound}
     * (`404`), the controlled error a genuinely-missing reference produces, rather
     * than passing the raw wire string to `getReference` (which would build a proxy
     * that errors on initialization — e.g. a `TypeError` assigning a non-integer key
     * to an int-PK entity — surfacing as a `500`; ADR 0038).
     */
    private function decodeLinkageId(string $relatedType, string $id): mixed
    {
        $encoder = $this->idEncoders->encoderFor($relatedType);
        if ($encoder === null) {
            return $id;
        }

        return $encoder->decode($id) ?? throw new ResourceNotFound();
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
