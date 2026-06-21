<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * Coordinates a snapshot/restore across **every** registered {@see InMemoryStore} as
 * ONE serialize pass, so cross-store object identity survives a rollback (the Slice C
 * in-memory carry-forward).
 *
 * The problem it solves: a parent object held in store A can reference a related
 * object held in store B (an {@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::mutateRelationship()}
 * wires it). The per-store `serialize(unserialize())` deep-clone followed every
 * reference, so store A's snapshot held a *clone* of B's related object — on restore,
 * A's parent no longer pointed at the live object in B (severed identity), and a graph
 * traversal (a parent → related read) saw a stale, detached copy. With independent
 * per-store snapshots this was invisible (each store restored itself in isolation), but
 * the atomic executor's cross-store rollback must reckon with it.
 *
 * The fix: every store registers here at construction. When the batch opens its
 * transaction, the FIRST store to {@see open()} a session triggers ONE
 * `serialize()` over the item maps of ALL registered stores together — so a shared
 * object reference is encoded once and reconstructed as a SINGLE instance shared
 * across the restored maps. {@see restore()} deserializes that one graph and hands
 * each store back its own (identity-coherent) map; {@see commit()} discards the
 * captured graph (the live writes stand). Each store's own `snapshot()`/`restore()`/
 * `discardSnapshot()` simply delegate here when a coordinator is wired (the previous
 * per-store deep-clone is the fallback when it is not — a store used in isolation).
 *
 * It is request-scoped: one session at a time (the atomic executor opens exactly one
 * batch). A store registered after a session opened still restores correctly — it just
 * was not part of the captured graph, so it restores to nothing captured (treated as an
 * empty pre-image); in practice every store is registered at container build, before any
 * request.
 */
final class InMemorySnapshotCoordinator
{
    /**
     * @var list<InMemoryStore>
     */
    private array $stores = [];

    /**
     * The captured pre-batch graph: a serialized blob of `[storeIndex => [id => object]]`
     * across every registered store, encoded in one pass so shared refs are preserved.
     * `null` when no session is open.
     */
    private ?string $captured = null;

    /**
     * The captured id-counter of each store (by index), rewound on restore alongside
     * the items so a rolled-back batch un-mints any ids it assigned.
     *
     * @var array<int, int>
     */
    private array $capturedNextIds = [];

    /**
     * Registers a store so it participates in coordinated snapshots. Idempotent — a
     * store already registered is not added twice.
     */
    public function register(InMemoryStore $store): void
    {
        if (!\in_array($store, $this->stores, true)) {
            $this->stores[] = $store;
        }
    }

    /**
     * Opens (or joins) the batch's snapshot session: the FIRST call captures the whole
     * registered-store graph in one serialize pass; a subsequent call within the same
     * session is a no-op (the graph is already captured). Returns true when this call
     * performed the capture (so the calling store need do nothing more), false when a
     * session was already open.
     */
    public function open(): bool
    {
        if ($this->captured !== null) {
            return false;
        }

        $maps = [];
        $this->capturedNextIds = [];
        foreach ($this->stores as $index => $store) {
            $maps[$index] = $store->exportItems();
            $this->capturedNextIds[$index] = $store->exportNextId();
        }

        // ONE serialize pass over every store's map together: a shared object reference
        // (a store-A parent → a store-B related object) is encoded once, so unserialize
        // reconstructs it as a single instance shared across the restored maps.
        $this->captured = \serialize($maps);

        return true;
    }

    /**
     * Restores every registered store to the captured pre-batch graph in one pass —
     * reconstructing the shared object graph (cross-store identity intact) — then ends
     * the session. A no-op when no session is open.
     */
    public function restore(): void
    {
        if ($this->captured === null) {
            return;
        }

        /** @var array<int, array<string, object>> $maps */
        $maps = \unserialize($this->captured);

        foreach ($this->stores as $index => $store) {
            $store->importItems($maps[$index] ?? [], $this->capturedNextIds[$index] ?? 1);
        }

        $this->captured = null;
        $this->capturedNextIds = [];
    }

    /**
     * Discards the captured graph without restoring it (the in-memory analogue of
     * committing — the batch's live writes stand) and ends the session. A no-op when no
     * session is open.
     */
    public function commit(): void
    {
        $this->captured = null;
        $this->capturedNextIds = [];
    }

    /**
     * Whether a snapshot session is currently open.
     */
    public function isOpen(): bool
    {
        return $this->captured !== null;
    }
}
