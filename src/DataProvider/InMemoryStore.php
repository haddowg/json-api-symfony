<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * The mutable in-memory backing store shared by an {@see InMemoryDataProvider}
 * (reads) and an {@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister}
 * (writes) for one resource type — so a created, updated, or deleted resource is
 * immediately visible to reads. It is the in-memory analogue of a database the
 * read and write halves of a real adapter both talk to (the Doctrine pair shares
 * one `EntityManager`); holding it as one object is what keeps the witness's
 * read/write split honest rather than two diverging copies.
 *
 * Items are keyed by their JSON:API id: reads seed an id-keyed map; writes derive
 * an item's id through the injected identifier closure. A store seeded for reads
 * only leaves the closure unset and never calls it.
 *
 * **Store-provided ids.** An optional `$assignId` closure mirrors a database
 * auto-increment: when an item is saved with an empty/absent id, the store mints
 * the next sequential id and writes it onto the item through the closure, then
 * keys by it. The sequence starts *above* the seeded rows (the count of explicit
 * numeric seed ids), so a created item gets a fresh, predictable id that
 * continues past the seed — exactly as a Doctrine `AUTO`/`IDENTITY` column does.
 * With no `$assignId` the store behaves exactly as before: a read-only store
 * still throws on an id-less write, and a writable store with client-supplied ids
 * is unaffected.
 *
 * Entities flow as `object` (not a templated entity type) — the registry resolves
 * a provider/persister per type and never needs a narrower static type, matching
 * how the reference Doctrine adapter implements the SPIs over `object`.
 */
final class InMemoryStore
{
    /**
     * @var array<string, object>
     */
    private array $itemsById = [];

    /**
     * @var \Closure(object): string
     */
    private readonly \Closure $identify;

    /**
     * @var (\Closure(object, string): void)|null
     */
    private readonly ?\Closure $assignId;

    /**
     * The next id the store-provided sequence will mint, kept above the seeded
     * rows so a created item never collides with a seed.
     */
    private int $nextId;

    /**
     * The pre-transaction state captured by {@see snapshot()} — a deep clone of
     * the items map plus the id counter — restored verbatim by {@see restore()}.
     * `null` when no snapshot is held.
     *
     * @var array{items: array<string, object>, nextId: int}|null
     */
    private ?array $snapshot = null;

    /**
     * The cross-store snapshot coordinator, when wired: snapshot/restore then run as
     * ONE serialize pass across every registered store, so cross-store object identity
     * survives an atomic rollback. Null = the legacy per-store deep-clone (a store used
     * in isolation, with no shared object graph to preserve).
     */
    private readonly ?InMemorySnapshotCoordinator $coordinator;

    /**
     * @param iterable<int|string, object>         $items    seed objects keyed by id
     * @param (\Closure(object): string)|null      $identify reads an item's id; required only for writes
     * @param (\Closure(object, string): void)|null $assignId writes a minted id onto an item; enables
     *                                                       store-provided (auto-increment) ids when set
     * @param ?InMemorySnapshotCoordinator         $coordinator coordinates a cross-store single-pass snapshot
     */
    public function __construct(iterable $items = [], ?\Closure $identify = null, ?\Closure $assignId = null, ?InMemorySnapshotCoordinator $coordinator = null)
    {
        $highestNumericId = 0;
        foreach ($items as $id => $item) {
            $id = (string) $id;
            $this->itemsById[$id] = $item;
            if (\ctype_digit($id)) {
                $highestNumericId = \max($highestNumericId, (int) $id);
            }
        }

        $this->identify = $identify ?? static function (object $item): string {
            throw new \LogicException(\sprintf(
                'This %s was seeded for reads only and cannot key a written item: '
                . 'construct it with an identifier closure to persist through it.',
                self::class,
            ));
        };

        $this->assignId = $assignId;
        // The sequence continues past the seeded rows, mirroring a database
        // auto-increment that restarts at 1 against a freshly recreated schema.
        $this->nextId = $highestNumericId + 1;

        // Register with the cross-store coordinator (when wired) so a coordinated
        // snapshot captures THIS store's graph in the shared single serialize pass.
        $this->coordinator = $coordinator;
        $coordinator?->register($this);
    }

    /**
     * @return list<object>
     */
    public function all(): array
    {
        return \array_values($this->itemsById);
    }

    public function find(string $id): ?object
    {
        return $this->itemsById[$id] ?? null;
    }

    /**
     * The JSON:API id of a stored `$item`, or `null` when the store does not hold
     * it. Used by {@see InMemoryDataProvider::countRelated()} to key a relation's
     * batched counts by the parent's wire id without needing the (write-only)
     * identifier closure — a read-seeded store reverse-looks-up the id from its
     * id-keyed map, so a read-only fixture can answer a count too. When the closure
     * IS set it is preferred (an exact, O(1) answer for a managed item).
     */
    public function idOf(object $item): ?string
    {
        try {
            $id = ($this->identify)($item);
            if ($id !== '') {
                return $id;
            }
        } catch (\LogicException) {
            // A read-only store has no identifier closure: fall through to the
            // reverse lookup off the id-keyed seed map.
        }

        $found = \array_search($item, $this->itemsById, true);

        return $found === false ? null : (string) $found;
    }

    public function save(object $item): void
    {
        $id = ($this->identify)($item);

        // A store-provided create: the item arrives without an id, so mint the
        // next sequential one and write it back onto the item before keying.
        if ($id === '' && $this->assignId !== null) {
            $id = (string) $this->nextId++;
            ($this->assignId)($item, $id);
        }

        $this->itemsById[$id] = $item;
    }

    public function remove(object $item): void
    {
        unset($this->itemsById[($this->identify)($item)]);
    }

    /**
     * Captures the current state — for the in-memory analogue of opening a
     * transaction ({@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::beginTransaction()}).
     *
     * **Coordinated path (the atomic executor).** When a
     * {@see InMemorySnapshotCoordinator} is wired, this delegates to it: the first
     * store to snapshot in a batch captures the item maps of EVERY registered store in
     * ONE serialize pass, so a parent in this store that references a related object in
     * another store is encoded once and restored as a SINGLE shared instance —
     * cross-store object identity survives a {@see restore()} (the Slice C carry-forward).
     *
     * **Isolated path (a store used alone).** With no coordinator each stored object is
     * **deep-cloned** (a `serialize(unserialize())` round-trip), because the persister's
     * {@see \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::update()} mutates
     * the SAME object reference in place and re-saves it under the same key: a shallow
     * array copy would still point at the now-mutated object, so a {@see restore()}
     * could not undo an in-place update. The id counter is captured too, so a
     * {@see restore()} also rewinds any ids minted in the batch.
     */
    public function snapshot(): void
    {
        if ($this->coordinator !== null) {
            $this->coordinator->open();

            return;
        }

        $items = [];
        foreach ($this->itemsById as $id => $item) {
            $items[$id] = $this->deepClone($item);
        }

        $this->snapshot = ['items' => $items, 'nextId' => $this->nextId];
    }

    /**
     * Reinstates the state captured by the last {@see snapshot()} — both the items
     * map (each object back to its pre-transaction field values) and the id counter
     * — discarding every write made since, then clears the snapshot. A no-op when no
     * snapshot is held. Delegates to the {@see InMemorySnapshotCoordinator} (which
     * restores the whole cross-store graph in one pass) when wired.
     */
    public function restore(): void
    {
        if ($this->coordinator !== null) {
            $this->coordinator->restore();

            return;
        }

        if ($this->snapshot === null) {
            return;
        }

        $this->itemsById = $this->snapshot['items'];
        $this->nextId = $this->snapshot['nextId'];
        $this->snapshot = null;
    }

    /**
     * Discards the held snapshot without restoring it — the in-memory analogue of
     * committing the transaction (the writes made since {@see snapshot()} stand). A
     * no-op when no snapshot is held. Delegates to the
     * {@see InMemorySnapshotCoordinator} (which discards the captured cross-store
     * graph) when wired.
     */
    public function discardSnapshot(): void
    {
        if ($this->coordinator !== null) {
            $this->coordinator->commit();

            return;
        }

        $this->snapshot = null;
    }

    /**
     * The current items map (id → object), for the {@see InMemorySnapshotCoordinator}'s
     * single-pass capture. The references are the live objects — the coordinator
     * serializes the union of every store's export together, so a shared reference is
     * preserved across the encoded graph.
     *
     * @internal coordinator use only
     *
     * @return array<string, object>
     */
    public function exportItems(): array
    {
        return $this->itemsById;
    }

    /**
     * The current id counter, captured by the coordinator alongside the items so a
     * coordinated {@see restore()} rewinds any ids minted in the batch.
     *
     * @internal coordinator use only
     */
    public function exportNextId(): int
    {
        return $this->nextId;
    }

    /**
     * Replaces this store's items map + id counter from the coordinator's restored
     * cross-store graph (the reconstructed objects, cross-store identity intact).
     *
     * @internal coordinator use only
     *
     * @param array<string, object> $items
     */
    public function importItems(array $items, int $nextId): void
    {
        $this->itemsById = $items;
        $this->nextId = $nextId;
    }

    /**
     * A deep clone of a stored object so the snapshot is independent of subsequent
     * in-place mutations. Uses PHP's recursive `unserialize(serialize())` round-trip
     * when the object is serializable (the common case — the witness's POJOs are),
     * falling back to a shallow `clone` for an object that cannot be serialized (a
     * closure-bearing object); the witness never stores such objects, so the
     * fallback is a safety net, not a normal path. Used only on the isolated
     * (no-coordinator) path — the coordinated path preserves cross-store identity by
     * serializing every store together.
     */
    private function deepClone(object $item): object
    {
        try {
            /** @var object $copy */
            $copy = \unserialize(\serialize($item));

            return $copy;
        } catch (\Throwable) {
            return clone $item;
        }
    }
}
