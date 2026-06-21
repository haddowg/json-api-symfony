<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * The segregated transactional capability a {@see DataPersisterInterface} MAY
 * implement so a batch of writes commits or rolls back atomically — the seam the
 * Atomic Operations executor drives (each operation in the batch persists through
 * the unchanged {@see DataPersisterInterface} write methods, but inside one
 * transaction opened on the persister, so nothing is durable until the executor
 * commits and the whole batch rolls back on any failure).
 *
 * It is **not** part of {@see DataPersisterInterface} (which stays frozen): a
 * persister that cannot transact simply does not implement it, and the executor
 * skips the transactional wrap for that type. The reference adapters both
 * implement it — the Doctrine persister over its `EntityManager`/`Connection`,
 * the in-memory witness over its {@see \haddowg\JsonApiBundle\DataProvider\InMemoryStore}
 * snapshot/restore.
 *
 * The contract the Atomic Operations executor relies on (empirically confirmed
 * against the bundle's real Doctrine + sqlite functional setup): with a
 * transaction open, the existing per-operation flush in
 * {@see DataPersisterInterface::create()}/{@see DataPersisterInterface::update()}/
 * {@see DataPersisterInterface::delete()}/{@see DataPersisterInterface::mutateRelationship()}
 * is **non-durable** (discarded by {@see rollback()}) yet **materialises any
 * store-generated id immediately** (so a later operation in the same batch can
 * reference a just-created resource's local id), and is committed atomically by
 * {@see commit()}. The write methods themselves are untouched — on the single-op
 * path no transaction is open, so they auto-commit exactly as today.
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
     * Discards every write made since {@see beginTransaction()}, leaving the store
     * as it was before the batch began.
     */
    public function rollback(): void;
}
