<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use Doctrine\Common\Collections\Collection;
use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\LinkRowWrite;
use haddowg\JsonApi\Collection\LinkRows\LinkRowWritePlan;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiBundle\DataProvider\InMemoryStore;
use haddowg\JsonApiBundle\Validation\RelationshipTypeUnacceptable;

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
 *
 * For relationship mutations the store holds the related **objects** (not raw
 * ids): so {@see mutateRelationship()} resolves a linkage id back to the stored
 * object through the optional `$relatedResolver` — a `(type, id) → ?object`
 * lookup the kernel wires across the related types' stores — and sets the parent's
 * association property to that object (or list of objects). A persister with no
 * resolver supports only whole-resource writes.
 */
final class InMemoryDataPersister implements DataPersisterInterface, TransactionalDataPersisterInterface, WritesLinkRowsInterface, WritesPositionsInterface
{
    /**
     * @param InMemoryStore                          $store           the store shared with the read provider
     * @param \Closure(): object                     $factory         builds a fresh, unpopulated instance for create
     * @param (\Closure(string, string): ?object)|null $relatedResolver resolves a related (type, id) to the stored object,
     *                                                                  for relationship mutation; null disables it
     */
    public function __construct(
        private readonly string $type,
        private readonly InMemoryStore $store,
        private readonly \Closure $factory,
        private readonly ?\Closure $relatedResolver = null,
    ) {}

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    /**
     * Snapshots the shared store, so the subsequent in-memory writes
     * ({@see create()}/{@see update()}/{@see delete()}/{@see mutateRelationship()})
     * can be discarded by {@see rollback()} or kept by {@see commit()} — the
     * in-memory analogue of opening a database transaction.
     */
    public function beginTransaction(): void
    {
        $this->store->snapshot();
    }

    /**
     * Keeps every write made since {@see beginTransaction()} (discarding the
     * snapshot), the in-memory analogue of committing.
     */
    public function commit(): void
    {
        $this->store->discardSnapshot();
    }

    /**
     * Restores the store to its snapshot, discarding every write made since
     * {@see beginTransaction()} — the in-memory analogue of a rollback.
     */
    public function rollback(): void
    {
        $this->store->restore();
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

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        $property = $relation->column() ?? $relation->name();

        if ($linkage instanceof ToOneRelationship) {
            // Replace (or, when the linkage is empty, clear) the to-one reference.
            $identifier = $linkage->resourceIdentifier;
            $entity->{$property} = $identifier?->id !== null
                ? $this->resolveRelated($this->relatedTypeOf($relation, $identifier), $identifier->id)
                : null;
        } else {
            $members = $this->applyToMany($entity, $property, $relation, $linkage, $mode);
            $held = $entity->{$property} ?? null;
            if ($held instanceof Collection) {
                // Refill the collection the model holds, so a typed property keeps its type.
                $held->clear();
                foreach ($members as $member) {
                    $held->add($member);
                }
            } else {
                $entity->{$property} = $members;
            }
        }

        if ($flush) {
            $this->store->save($entity);
        }

        return $entity;
    }

    /**
     * Mutates a positioned to-many and writes each member's position onto it. A pivot
     * relation's positions live on pivot rows, which the in-memory store doesn't hold, so
     * only its members change.
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
        if ($relation instanceof PivotRelationInterface) {
            return $this->mutateRelationship($type, $entity, $relation, $linkage, $mode, $flush);
        }

        $property = $relation->column() ?? $relation->name();
        $current = PositionTargets::current($this->keyed($this->currentList($entity, $property)), $column);
        $this->mutateRelationship($type, $entity, $relation, $linkage, $mode, false);

        $incoming = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            if ($identifier->id !== null) {
                $incoming[] = $identifier->id;
            }
        }

        $this->writePositions($entity, $current, PositionTargets::for($mode, $current, $incoming), $this->keyed($this->currentList($entity, $property)), $column, $flush);

        return $entity;
    }

    /**
     * The store holds no unique position key, so the final positions are written at once.
     */
    public function writePositions(object $parent, array $current, array $target, array $rows, string $column, bool $flush = true): void
    {
        PositionTargets::writeFinal($target, $rows, $column);

        if ($flush) {
            $this->store->save($parent);
        }
    }

    /**
     * @param list<object> $members
     *
     * @return array<string, object>
     */
    private function keyed(array $members): array
    {
        $keyed = [];
        foreach ($members as $member) {
            $keyed[$this->idOf($member)] = $member;
        }

        return $keyed;
    }

    /**
     * Applies a declared `MorphToMany`'s link-row plan to the rows held on the parent
     * through core's {@see InMemoryLinkRows::write()}, which checks no pass puts two rows at
     * one position. A new row's scalar values take the types its class declares, the
     * in-memory store's storage form, and the rows go back into the collection the parent
     * held them in.
     */
    public function writeLinkRows(object $parent, MorphToMany $relation, LinkRowWritePlan $plan, bool $flush = true): object
    {
        $through = $relation->linkRowFields()?->through;
        $inserts = [];
        foreach ($plan->inserts as $insert) {
            $values = [];
            foreach ($insert->values as $field => $value) {
                $values[$field] = $through !== null ? $this->storageValue($through, $field, $value) : $value;
            }
            $inserts[] = new LinkRowWrite($insert->member, $values);
        }

        $written = (new InMemoryLinkRows())->write(
            $relation,
            $parent,
            new LinkRowWritePlan($plan->deletes, $plan->temporary, $plan->updates, $inserts),
            $through !== null && \class_exists($through) ? static fn(): object => self::newRow($through) : null,
            static fn(mixed $held, array $rows): mixed => self::storeRows($relation, $held, $rows),
        );
        $parent = \is_object($written) ? $written : $parent;

        if ($flush) {
            $this->store->save($parent);
        }

        return $parent;
    }

    /**
     * Stores `$rows` back on the parent: refilling the collection it holds them in, so a
     * typed collection property keeps its type, else as a list.
     *
     * @param list<mixed> $rows
     */
    private static function storeRows(MorphToMany $relation, mixed $parent, array $rows): mixed
    {
        $field = $relation->column() ?? $relation->name();
        $held = Accessor::get($parent, $field);
        if (!$held instanceof Collection) {
            return Accessor::set($parent, $field, $rows);
        }

        $held->clear();
        foreach ($rows as $row) {
            $held->add($row);
        }

        return $parent;
    }

    /**
     * A new link row: constructed when its class takes no required argument, else
     * created without its constructor, as Doctrine does, since the plan sets every field
     * the row needs.
     *
     * @param class-string $class
     */
    private static function newRow(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return $constructor === null || $constructor->getNumberOfRequiredParameters() === 0
            ? $reflection->newInstance()
            : $reflection->newInstanceWithoutConstructor();
    }

    /**
     * `$value` as the scalar type `$class` declares for `$field`, so a key that arrives as
     * a numeric string lands in an `int` property.
     */
    private function storageValue(string $class, string $field, mixed $value): mixed
    {
        if (!\is_string($value) || !\class_exists($class) || !\property_exists($class, $field)) {
            return $value;
        }

        $type = (new \ReflectionProperty($class, $field))->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

        return match (true) {
            $name === 'int' && \preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            $name === 'float' && \is_numeric($value) => (float) $value,
            default => $value,
        };
    }

    /**
     * Computes the new to-many object list for `$mode`: replace sets the whole
     * resolved list, add appends the resolved members (idempotent on id), remove
     * subtracts the linkage ids from the current list.
     *
     * @return list<object>
     */
    private function applyToMany(
        object $entity,
        string $property,
        RelationInterface $relation,
        ToManyRelationship $linkage,
        Mode $mode,
    ): array {
        $incoming = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            if ($identifier->id !== null) {
                $incoming[$identifier->id] = $this->relatedTypeOf($relation, $identifier);
            }
        }
        $incomingIds = \array_map('strval', \array_keys($incoming));

        if ($mode === Mode::Remove) {
            $remove = \array_fill_keys($incomingIds, true);

            return \array_values(\array_filter(
                $this->currentList($entity, $property),
                fn(object $member): bool => !isset($remove[$this->idOf($member)]),
            ));
        }

        $resolved = [];
        foreach ($incoming as $id => $relatedType) {
            $id = (string) $id;
            $related = $this->resolveRelated($relatedType, $id);
            if ($related !== null) {
                $resolved[$id] = $related;
            }
        }

        if ($mode === Mode::Replace) {
            return \array_values($resolved);
        }

        // Mode::Add — append the resolved members, deduplicating on id so add is
        // idempotent (an already-present member is not duplicated).
        $next = [];
        foreach ($this->currentList($entity, $property) as $member) {
            $next[$this->idOf($member)] = $member;
        }
        foreach ($resolved as $id => $member) {
            $next[$id] = $member;
        }

        return \array_values($next);
    }

    /**
     * The parent's current to-many member list, normalised to a list of objects.
     *
     * @return list<object>
     */
    private function currentList(object $entity, string $property): array
    {
        $current = $entity->{$property} ?? [];

        return \array_values(\array_filter(
            \is_iterable($current) ? [...$current] : [],
            static fn(mixed $member): bool => \is_object($member),
        ));
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

    private function resolveRelated(string $relatedType, string $id): ?object
    {
        if ($this->relatedResolver === null) {
            throw new \LogicException(\sprintf(
                'The %s for type "%s" cannot resolve a related object: construct it with a related-object '
                . 'resolver to mutate relationships through it.',
                self::class,
                $this->type,
            ));
        }

        return ($this->relatedResolver)($relatedType, $id);
    }

    /**
     * The JSON:API id of a stored related object — read off its public `id`
     * member, the same shape the seed objects use.
     */
    private function idOf(object $member): string
    {
        /** @var mixed $id */
        $id = $member->id ?? null;

        return \is_scalar($id) ? (string) $id : \spl_object_hash($member);
    }
}
