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
     * @param iterable<int|string, object>         $items    seed objects keyed by id
     * @param (\Closure(object): string)|null      $identify reads an item's id; required only for writes
     * @param (\Closure(object, string): void)|null $assignId writes a minted id onto an item; enables
     *                                                       store-provided (auto-increment) ids when set
     */
    public function __construct(iterable $items = [], ?\Closure $identify = null, ?\Closure $assignId = null)
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
}
