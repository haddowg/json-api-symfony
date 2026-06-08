<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

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
 * Relationship mutation (the `/{type}/{id}/relationships/{rel}` endpoints) is a
 * later phase; this interface covers whole-resource writes only.
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
}
