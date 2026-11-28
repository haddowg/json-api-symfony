<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * One pivot relation's batched pivot map for every parent rendered at one include path,
 * `parentWireId => memberId => [field => value]`, read on first use. A relation's linkage
 * renders its data only for some parents and some requests, so the read waits until a
 * member's `meta.pivot` is rendered and then covers every parent at once, never one parent
 * at a time.
 */
final class PivotMapBatch
{
    /** @var array<array-key, array<array-key, array<string, mixed>>>|null */
    private ?array $map = null;

    /**
     * @param \Closure(): array<array-key, array<array-key, array<string, mixed>>> $read
     * @param string                   $provider      the class of the provider the read asks
     * @param IdEncoderInterface|null $parentEncoder the parent type's id encoder, which decodes a wire id to the id a provider may key by
     * @param IdEncoderInterface|null $memberEncoder the related type's id encoder, likewise for the members
     */
    public function __construct(
        private readonly \Closure $read,
        public readonly string $provider,
        private readonly ?IdEncoderInterface $parentEncoder = null,
        public readonly ?IdEncoderInterface $memberEncoder = null,
    ) {}

    /**
     * The pivot values of `$parentWireId`'s members, keyed by member id; empty when the
     * parent has no rows. A uuid-shaped id the provider keyed in another case matches.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forParent(string $parentWireId): array
    {
        $this->map ??= ($this->read)();

        return PivotKeys::find($this->map, $parentWireId, $this->parentEncoder) ?? [];
    }
}
