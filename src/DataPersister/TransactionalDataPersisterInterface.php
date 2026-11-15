<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * The segregated transactional capability a {@see DataPersisterInterface} MAY
 * implement so a write commits or rolls back as one unit.
 *
 * The bundle opens a transaction on it around every write it runs through it (see
 * {@see WriteTransaction}). A single create, update, delete or relationship mutation runs
 * from its first write step (the body's relationships, hydration, entity validation and
 * every before hook) through the persister's write in one transaction, and commits before
 * the after hooks run. An action called with POST, PATCH, PUT or DELETE runs in one on
 * its mount type's persister. An Atomic Operations batch opens one on each participating
 * persister for the whole batch, and the writes inside it run in that one.
 *
 * It is **not** part of {@see DataPersisterInterface} (which stays frozen): a
 * persister that cannot transact simply does not implement it, its single writes run
 * without a transaction, and the Atomic Operations executor refuses a batch that touches
 * its type. The reference adapters both implement it — the Doctrine persister over its
 * `EntityManager`/`Connection`, the in-memory witness over its
 * {@see \haddowg\JsonApiBundle\DataProvider\InMemoryStore} snapshot/restore.
 *
 * The contract the bundle relies on (empirically confirmed against the bundle's real
 * Doctrine + sqlite functional setup): with a transaction open, the per-operation flush
 * in {@see DataPersisterInterface::create()}/{@see DataPersisterInterface::update()}/
 * {@see DataPersisterInterface::delete()}/{@see DataPersisterInterface::mutateRelationship()},
 * and any flush a lifecycle hook makes on the same connection, is **non-durable**
 * (discarded by {@see rollback()}) yet **materialises any store-generated id
 * immediately** (so a later operation in the same batch can reference a just-created
 * resource's local id), and is committed atomically by {@see commit()}.
 *
 * Transactions nest: a hook may open its own inside the bundle's. {@see commit()} closes
 * one level, and {@see rollback()} discards everything since the matching
 * {@see beginTransaction()}, a transaction a hook opened inside it and left open included,
 * so the connection is never left inside a transaction for the next request a
 * long-running worker serves.
 */
interface TransactionalDataPersisterInterface
{
    /**
     * Opens a transaction so the subsequent per-operation writes are buffered —
     * non-durable until {@see commit()} (or discarded by {@see rollback()}).
     */
    public function beginTransaction(): void;

    /**
     * Durably commits every write made since {@see beginTransaction()}.
     */
    public function commit(): void;

    /**
     * Discards every write made since the matching {@see beginTransaction()}, leaving the
     * store as it was before it, and closes any transaction opened inside it. Safe to call
     * when the transaction has already ended, as it is after a failed commit.
     */
    public function rollback(): void;
}
