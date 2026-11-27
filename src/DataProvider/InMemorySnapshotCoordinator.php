<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Coordinates a snapshot/restore across **every** registered {@see InMemoryStore} as
 * ONE serialize pass, so cross-store object identity survives a rollback.
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
 *
 * It implements {@see ResetInterface} (auto-tagged `kernel.reset` when registered as a
 * service), mirroring the {@see \haddowg\JsonApiBundle\DataPersister\WriteTransactionContext}:
 * a batch that left a session open undrained (an exception between {@see open()} and
 * {@see restore()}/{@see commit()}) would otherwise carry its captured pre-image into the
 * NEXT batch in a long-lived container (a worker reusing the kernel), poisoning it — and a
 * stale capture also pins the captured object graph in memory. {@see reset()} discards the
 * captured state between requests so no batch inherits a previous one's open session.
 */
final class InMemorySnapshotCoordinator implements ResetInterface
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
     * How many transactions are open. A write opens its own transaction, and a lifecycle
     * hook may open one inside it, so they nest: only the outermost open captures the
     * pre-image and only the outermost commit discards it, so an inner commit leaves the
     * outer one able to roll back. A {@see restore()} unwinds every level at once, as
     * rolling back an outer database transaction discards its savepoints.
     */
    private int $depth = 0;

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
        ++$this->depth;
        if ($this->captured !== null) {
            return false;
        }

        $maps = [];
        $pivots = [];
        $this->capturedNextIds = [];
        foreach ($this->stores as $index => $store) {
            $maps[$index] = $store->exportItems();
            $pivots[$index] = $store->exportPivotRows();
            $this->capturedNextIds[$index] = $store->exportNextId();
        }

        // ONE serialize pass over every store's map together: a shared object reference
        // (a store-A parent → a store-B related object) is encoded once, so unserialize
        // reconstructs it as a single instance shared across the restored maps.
        $this->captured = \serialize([$maps, $pivots]);

        return true;
    }

    /**
     * Restores every registered store to the captured pre-batch graph in one pass —
     * reconstructing the shared object graph (cross-store identity intact) — then ends
     * the session. A no-op when no session is open.
     */
    public function restore(): void
    {
        $this->depth = 0;
        if ($this->captured === null) {
            return;
        }

        /** @var array{array<int, array<string, object>>, array<int, array<string, array<array-key, array<array-key, array<string, mixed>>>>>} $captured */
        $captured = \unserialize($this->captured);
        [$maps, $pivots] = $captured;

        foreach ($this->stores as $index => $store) {
            $store->importItems($maps[$index] ?? [], $this->capturedNextIds[$index] ?? 1);
            $store->importPivotRows($pivots[$index] ?? []);
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
        if ($this->depth > 0) {
            --$this->depth;
        }
        if ($this->depth > 0) {
            return;
        }

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

    /**
     * Discards any open snapshot session between requests in a long-lived container
     * (the `kernel.reset` hook), mirroring the
     * {@see \haddowg\JsonApiBundle\DataPersister\WriteTransactionContext}: a batch that
     * left a session open (an exception between {@see open()} and {@see restore()}/
     * {@see commit()}) never poisons the next batch with its captured pre-image, and the
     * captured graph is released. It does NOT restore — a reset is not a rollback; a
     * half-applied in-memory batch's live writes simply stand (the data store is the
     * authority), exactly as the live persisters' state would on the same leak.
     */
    public function reset(): void
    {
        $this->captured = null;
        $this->capturedNextIds = [];
        $this->depth = 0;
    }
}
