<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\Field\StoredIdForms;

/**
 * One pivot relation's batched pivot map for every parent rendered at one include path,
 * `parentWireId => memberId => [field => value]`, read on first use. A relation's linkage
 * renders its data only for some parents and some requests, so the read waits until a
 * member's `meta.pivot` is rendered and then covers every parent at once, never one parent
 * at a time.
 */
final class PivotMapBatch
{
    /** @var array<string, array<string, array<string, mixed>>>|null */
    private ?array $map = null;

    /**
     * @param \Closure(): array<string, array<string, array<string, mixed>>> $read
     */
    public function __construct(private readonly \Closure $read) {}

    /**
     * The pivot values of `$parentWireId`'s members, keyed by member id; empty when the
     * parent has no rows. A uuid-shaped id the provider keyed in another case matches.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forParent(string $parentWireId): array
    {
        $this->map ??= ($this->read)();
        if (isset($this->map[$parentWireId])) {
            return $this->map[$parentWireId];
        }

        $wanted = StoredIdForms::comparable($parentWireId);
        foreach ($this->map as $key => $entry) {
            if (StoredIdForms::comparable((string) $key) === $wanted) {
                return $entry;
            }
        }

        return [];
    }
}
