<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The write-half data-source SPI: the storage-agnostic contract the
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} delegates to for
 * `POST /{type}`, `PATCH /{type}/{id}` and `DELETE /{type}/{id}`.
 *
 * It is the write twin of {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface}:
 * a persister is resolved per resource type via
 * {@see DataPersisterRegistry::forType()} — {@see supports()} declaring which
 * type(s) it answers for — and the bundled Doctrine persister registers as the
 * `-128` fallback, so an application persister at the default priority shadows it.
 *
 * The handler drives the lifecycle: it asks the persister for a blank instance
 * ({@see instantiate()}), lets core's hydrator populate it from the request
 * document, then hands the populated entity back to {@see create()} (a fresh
 * resource) or — having loaded the target through the read provider —
 * {@see update()}/{@see delete()}. The persister only commits; the read provider
 * has already loaded an update/delete target (through any scoping it applies), and
 * the reference adapters share storage so that instance is the one committed.
 *
 * Entities flow as `object`: the handler resolves the persister by type and never
 * needs a narrower static type, so — unlike the covariant read provider — this
 * contract is not templated over the entity type.
 *
 * The `/{type}/{id}/relationships/{rel}` endpoints add {@see mutateRelationship()}:
 * core validates the request shape (cardinality + mutability flags), then the
 * persister resolves the linkage's resource-identifier ids to the actual related
 * objects/references and mutates the parent's association — the persister owns the
 * storage mapping (ADR 0010), so it owns the id → object resolution a relationship
 * mutation needs. The whole-resource writes ({@see create()}/{@see update()}/
 * {@see delete()}) stay unchanged.
 */
interface DataPersisterInterface
{
    /**
     * Whether this persister answers for the given resource type.
     */
    public function supports(string $type): bool;

    /**
     * A fresh, unpopulated instance of `$type` for the handler to hydrate on
     * create (the persister owns the storage mapping, so it owns instantiation).
     */
    public function instantiate(string $type): object;

    /**
     * Persists a new resource of `$type` and returns it (with any
     * store-generated id populated).
     */
    public function create(string $type, object $entity): object;

    /**
     * Commits the changes hydrated onto an existing resource of `$type` and
     * returns it.
     */
    public function update(string $type, object $entity): object;

    /**
     * Removes the given resource of `$type` from the store.
     */
    public function delete(string $type, object $entity): void;

    /**
     * Applies a relationship-endpoint mutation to `$entity` and commits it.
     *
     * Core has already loaded `$entity` (the parent, of `$type`) through the read
     * provider and validated the request shape — cardinality (add/remove only on a
     * to-many) and the relation's mutability flags
     * ({@see RelationInterface::allowsReplace()} / {@see RelationInterface::allowsRemove()}).
     * The persister resolves the `$linkage`'s resource-identifier ids to the
     * related objects/references (via its own storage) and mutates `$relation`'s
     * association on the parent under `$mode`:
     *  - {@see Mode::Replace} ({@see ToOneRelationship}) — set or clear the to-one
     *    reference (an empty linkage clears it);
     *  - {@see Mode::Replace} ({@see ToManyRelationship}) — set the whole to-many set;
     *  - {@see Mode::Add} — add the linkage members to the to-many set
     *    (idempotent — an already-present member is not duplicated);
     *  - {@see Mode::Remove} — remove the linkage members from the to-many set.
     *
     * The mutated parent is returned (so the handler can render the resulting
     * linkage). `$flush` controls whether the mutation is committed immediately:
     * the relationship endpoints commit per mutation (`$flush = true`, the default),
     * but a whole-resource write that embeds relationships in `data.relationships`
     * (ADR 0018) applies each relationship with `$flush = false` and lets the
     * subsequent {@see create()}/{@see update()} own the single commit — so a
     * not-yet-persisted create target is never flushed mid-association.
     *
     * @param ToOneRelationship|ToManyRelationship $linkage the parsed relationship-endpoint linkage
     */
    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object;
}
