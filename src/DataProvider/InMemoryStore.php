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
     * @param iterable<int|string, object>    $items    seed objects keyed by id
     * @param (\Closure(object): string)|null $identify reads an item's id; required only for writes
     */
    public function __construct(iterable $items = [], ?\Closure $identify = null)
    {
        foreach ($items as $id => $item) {
            $this->itemsById[(string) $id] = $item;
        }

        $this->identify = $identify ?? static function (object $item): string {
            throw new \LogicException(\sprintf(
                'This %s was seeded for reads only and cannot key a written item: '
                . 'construct it with an identifier closure to persist through it.',
                self::class,
            ));
        };
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
        $this->itemsById[($this->identify)($item)] = $item;
    }

    public function remove(object $item): void
    {
        unset($this->itemsById[($this->identify)($item)]);
    }
}
