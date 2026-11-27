<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Resource\ResourceMeta;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Serializer\CountableControlsInterface;
use haddowg\JsonApi\Serializer\CountableSelfInterface;
use haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface;
use haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\RendersDeclaredLinksInterface;
use haddowg\JsonApi\Serializer\RendersResourceMetaInterface;
use haddowg\JsonApi\Serializer\SelfLinkAwareInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\SerializesNestedFieldsetsInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApiBundle\DataProvider\PivotFields;

/**
 * A {@see SerializerInterface} decorator that renders a `belongsToMany` pivot
 * relation's per-member pivot values as `meta.pivot`, mirroring how
 * {@see \haddowg\JsonApi\Serializer\PolymorphicSerializer} decorates a polymorphic
 * relation. It wraps the related type's real serializer and delegates everything to
 * it EXCEPT {@see getMeta()}, where it merges the member's pivot values
 * (`pivotMap[id]`, read from the SAME association-entity query) under a top-level
 * `pivot` key into the inner serializer's meta.
 *
 * Pivot meta rides core's existing `getMeta()` path, which the transformer renders
 * into BOTH the full resource (the related endpoint `GET /{type}/{id}/{rel}`) and
 * the resource identifier (the relationship-linkage endpoint
 * `GET /{type}/{id}/relationships/{rel}`) — so binding this serializer for those two
 * renders needs no core change. A member with no pivot entry (it should not happen
 * for a pivot collection, but is possible for the relationship endpoint's full
 * association) renders the inner meta unchanged.
 *
 * The `pivot` key keeps the values namespaced so they never collide with the
 * member resource's own meta, and so a client can tell pivot data from intrinsic
 * meta. Every other optional serializer interface forwards to the related serializer
 * through {@see ForwardsSerializerInterfaces}, so a member keeps its links, URI type,
 * self-link toggle and counts.
 *
 * Given the relation, it renders the fields core's
 * {@see BelongsToMany::readablePivotFieldsFor()} returns for the request and the member
 * (neither hidden nor write-only, predicates included, the same rule a top-level
 * attribute follows). Each renders `null` when the row holds no value for it, which is
 * what the response pivot schema's `required` promises. The map itself keeps write-only
 * values, because the validator reads them when it merges a partial pivot update.
 *
 * Given the parent too, a pivot field read through a hook, a computed field or a stored
 * one with an `extractUsing()` hook, renders from core's
 * {@see BelongsToMany::hookedPivotValues()} for the parent, the member and the member's
 * stored values from the map, so its hook gets a {@see \haddowg\JsonApi\Resource\Field\PivotEdge}
 * and never the association entity.
 *
 * Where no pivot storage was read, as on the in-memory provider or for a relation whose
 * fields are all computed, it is built computed-only: each member renders just the
 * computed fields, evaluated with no stored values, and never a stored field, so a
 * computed field renders the same on every provider.
 */
final class PivotMetaSerializer implements
    SerializerInterface,
    IncludeControlsInterface,
    CountableControlsInterface,
    CountableSelfInterface,
    DeclaresFieldNamesInterface,
    DeclaresEagerLoadsInterface,
    DeclaresRelationsInterface,
    SelfLinkAwareInterface,
    RendersDeclaredLinksInterface,
    UriTypeAwareInterface,
    SerializerResolverAwareInterface,
    RendersResourceMetaInterface,
    SerializesNestedFieldsetsInterface
{
    use ForwardsSerializerInterfaces;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $resolved = null;

    /**
     * @param SerializerInterface                  $inner    the related type's real serializer
     * @param array<string, array<string, mixed>>|(\Closure(): array<string, array<string, mixed>>) $pivotMap `memberId => [pivotField => typed value]`, or the read that returns it when a member first renders
     * @param RelationInterface|null               $relation the pivot relation whose fields decide what renders; without it the map renders as given
     * @param object|null                          $parent   the parent whose relation renders, which hooked pivot fields read
     * @param string|null                          $outputTimezone the server's default output zone a hooked date field adopts
     * @param bool                                 $computedOnly   whether no pivot storage was read, so only the computed fields render
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly array|\Closure $pivotMap,
        private readonly ?RelationInterface $relation = null,
        private readonly ?object $parent = null,
        private readonly ?string $outputTimezone = null,
        private readonly bool $computedOnly = false,
    ) {}

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->withPivot($this->inner->getMeta($object, $request), $object, $request);
    }

    public function renderResourceMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        $meta = $this->inner instanceof RendersResourceMetaInterface
            ? $this->inner->renderResourceMeta($object, $request)
            : ResourceMeta::render($this->inner, $object, $request);

        return $this->withPivot($meta, $object, $request);
    }

    /**
     * `$meta` with the member's pivot values under `pivot`.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function withPivot(array $meta, mixed $object, JsonApiRequestInterface $request): array
    {
        if ($this->computedOnly) {
            return $this->withComputedOnly($meta, $object, $request);
        }

        $id = $this->inner->getId($object);
        $pivot = $this->map()[$id] ?? $this->byComparableId()[StoredIdForms::comparable($id)] ?? null;
        $hooked = $this->parent !== null && $this->relation !== null && PivotFields::readsThroughHook($this->relation);
        if ($hooked && $this->relation instanceof BelongsToMany && $pivot !== null) {
            $pivot = [...$pivot, ...$this->relation->hookedPivotValues($this->parent, $object, $pivot, $request, $this->outputTimezone)];
        }
        if ($pivot !== null && $this->relation !== null) {
            $pivot = $this->readable($pivot, $request, $object);
        }
        if ($pivot !== null && $pivot !== []) {
            $meta['pivot'] = $pivot;
        }

        return $meta;
    }

    /**
     * `$meta` with the member's readable computed pivot fields under `pivot`, evaluated
     * with no stored values. A stored field never renders here, as no storage was read.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function withComputedOnly(array $meta, mixed $object, JsonApiRequestInterface $request): array
    {
        if (!$this->relation instanceof BelongsToMany || $this->parent === null) {
            return $meta;
        }

        $computed = [];
        foreach ($this->relation->computedPivotFields() as $field) {
            $computed[$field->name()] = true;
        }
        if ($computed === []) {
            return $meta;
        }

        $values = $this->relation->hookedPivotValues($this->parent, $object, [], $request, $this->outputTimezone);
        $pivot = [];
        foreach ($this->relation->readablePivotFieldsFor($request, $object) as $field) {
            if (isset($computed[$field->name()])) {
                $pivot[$field->name()] = $values[$field->name()] ?? null;
            }
        }
        if ($pivot !== []) {
            $meta['pivot'] = $pivot;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $pivot
     *
     * @return array<string, mixed>
     */
    private function readable(array $pivot, JsonApiRequestInterface $request, mixed $member): array
    {
        if (!$this->relation instanceof BelongsToMany) {
            return [];
        }

        $readable = [];
        foreach ($this->relation->readablePivotFieldsFor($request, $member) as $field) {
            $readable[$field->name()] = $pivot[$field->name()] ?? null;
        }

        return $readable;
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getRelationships($object, $request);
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function map(): array
    {
        if ($this->pivotMap instanceof \Closure) {
            $this->resolved ??= ($this->pivotMap)();

            return $this->resolved;
        }

        return $this->pivotMap;
    }

    /**
     * The pivot map keyed by each member's comparable wire id, so a uuid-shaped id the
     * provider keyed in capitals matches the lower case the member renders in.
     *
     * @return array<string, array<string, mixed>>
     */
    private function byComparableId(): array
    {
        $keyed = [];
        foreach ($this->map() as $id => $values) {
            $keyed[StoredIdForms::comparable($id)] = $values;
        }

        return $keyed;
    }
}
