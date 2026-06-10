<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
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
 *
 * For relationship mutations the store holds the related **objects** (not raw
 * ids): so {@see mutateRelationship()} resolves a linkage id back to the stored
 * object through the optional `$relatedResolver` — a `(type, id) → ?object`
 * lookup the kernel wires across the related types' stores — and sets the parent's
 * association property to that object (or list of objects). A persister with no
 * resolver supports only whole-resource writes.
 */
final class InMemoryDataPersister implements DataPersisterInterface
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
        $relatedType = $relation->relatedTypes()[0] ?? '';

        if ($linkage instanceof ToOneRelationship) {
            // Replace (or, when the linkage is empty, clear) the to-one reference.
            $entity->{$property} = $linkage->resourceIdentifier?->id !== null
                ? $this->resolveRelated($relatedType, $linkage->resourceIdentifier->id)
                : null;
        } else {
            $entity->{$property} = $this->applyToMany($entity, $property, $relatedType, $linkage, $mode);
        }

        if ($flush) {
            $this->store->save($entity);
        }

        return $entity;
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
        string $relatedType,
        ToManyRelationship $linkage,
        Mode $mode,
    ): array {
        $incomingIds = \array_values(\array_filter(
            $linkage->getResourceIdentifierIds(),
            static fn(?string $id): bool => $id !== null,
        ));

        if ($mode === Mode::Remove) {
            $remove = \array_fill_keys($incomingIds, true);

            return \array_values(\array_filter(
                $this->currentList($entity, $property),
                fn(object $member): bool => !isset($remove[$this->idOf($member)]),
            ));
        }

        $resolved = [];
        foreach ($incomingIds as $id) {
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
