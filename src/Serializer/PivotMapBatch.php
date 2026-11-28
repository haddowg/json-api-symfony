<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * One pivot relation's pivot values for every parent rendered at one include path. A
 * parent whose page of the relation was read through its pivot query already holds its
 * members' values, so they render from that page. The rest are read on first use, for
 * every parent still to read at once, never one parent at a time. A relation's linkage
 * renders its data only for some parents and some requests, so nothing is read until a
 * member's `meta.pivot` renders.
 */
final class PivotMapBatch
{
    /** @var array<array-key, array<array-key, array<string, mixed>>> */
    private array $map = [];

    /** @var array<int, true> the parents read so far, keyed by object id */
    private array $readIds = [];

    /**
     * @param \Closure(list<object>): array<array-key, array<array-key, array<string, mixed>>> $read reads `parentWireId => memberId => [field => value]` for the parents given
     * @param list<object>             $parents       every parent rendered at the path
     * @param string                   $provider      the class of the provider the read asks
     * @param IdEncoderInterface|null $parentEncoder the parent type's id encoder, which decodes a wire id to the id a provider may key by
     * @param IdEncoderInterface|null $memberEncoder the related type's id encoder, likewise for the members
     * @param array<int, array<array-key, array<string, mixed>>> $loaded the members' values each parent's page was read with, keyed by parent object id
     */
    public function __construct(
        private readonly \Closure $read,
        private readonly array $parents,
        public readonly string $provider,
        private readonly ?IdEncoderInterface $parentEncoder = null,
        public readonly ?IdEncoderInterface $memberEncoder = null,
        private readonly array $loaded = [],
    ) {}

    /**
     * The pivot values of `$parentWireId`'s members, keyed by member id; empty when the
     * parent has no rows. A uuid-shaped id the provider keyed in another case matches.
     *
     * @return array<array-key, array<string, mixed>>
     */
    public function forParent(string $parentWireId): array
    {
        $this->readParents(true);

        return PivotKeys::find($this->map, $parentWireId, $this->parentEncoder) ?? [];
    }

    /**
     * The pivot values of member `$memberWireId` of `$parent`, or `null` when it has no
     * row. A member missing from the page its parent was read with is read like any other.
     *
     * @return array<string, mixed>|null
     */
    public function rowFor(string $parentWireId, ?object $parent, string $memberWireId): ?array
    {
        $loaded = $parent === null ? null : $this->loaded[\spl_object_id($parent)] ?? null;
        if ($loaded !== null) {
            $row = PivotKeys::find($loaded, $memberWireId, $this->memberEncoder);
            if ($row !== null) {
                return $row;
            }
        }

        $this->readParents($loaded !== null);

        return PivotKeys::find(PivotKeys::find($this->map, $parentWireId, $this->parentEncoder) ?? [], $memberWireId, $this->memberEncoder);
    }

    /**
     * Reads every parent not read yet, leaving out those whose page was loaded unless
     * `$loadedToo`.
     */
    private function readParents(bool $loadedToo): void
    {
        $parents = [];
        foreach ($this->parents as $parent) {
            $id = \spl_object_id($parent);
            if (!isset($this->readIds[$id]) && ($loadedToo || !isset($this->loaded[$id]))) {
                $parents[] = $parent;
                $this->readIds[$id] = true;
            }
        }
        if ($parents !== []) {
            $this->map = ($this->read)($parents) + $this->map;
        }
    }
}
