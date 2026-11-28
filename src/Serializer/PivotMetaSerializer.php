<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
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
 * renders needs no core change. A member of a relation with stored pivot fields that
 * the map holds no row for fails the render with {@see MissingPivotRow}, naming the
 * {@see PivotRowSource} the map came from.
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

    /**
     * @param SerializerInterface                  $inner    the related type's real serializer
     * @param array<array-key, array<string, mixed>>|(\Closure(string): (array<string, mixed>|null)) $pivotMap `memberId => [pivotField => typed value]`, or the lookup that returns a member's values by its id when it renders
     * @param RelationInterface|null               $relation the pivot relation whose fields decide what renders; without it the map renders as given
     * @param object|null                          $parent   the parent whose relation renders, which hooked pivot fields read
     * @param string|null                          $outputTimezone the server's default output zone a hooked date field adopts
     * @param bool                                 $computedOnly   whether no pivot storage was read, so only the computed fields render
     * @param PivotRowSource|null                  $source         the parent and provider the rows came from, named when a member has none
     * @param IdEncoderInterface|null              $memberEncoder  the related type's id encoder, which decodes a member's wire id to the id the map may be keyed by
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly array|\Closure $pivotMap,
        private readonly ?RelationInterface $relation = null,
        private readonly ?object $parent = null,
        private readonly ?string $outputTimezone = null,
        private readonly bool $computedOnly = false,
        private readonly ?PivotRowSource $source = null,
        private readonly ?IdEncoderInterface $memberEncoder = null,
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
        $pivot = $this->pivotMap instanceof \Closure ? ($this->pivotMap)($id) : PivotKeys::find($this->pivotMap, $id, $this->memberEncoder);
        if (!$this->relation instanceof BelongsToMany) {
            // Without the relation the map renders as given, an empty entry as an object.
            if ($pivot !== null) {
                $meta['pivot'] = $pivot === [] ? new \stdClass() : $pivot;
            }

            return $meta;
        }

        // A relation declaring no pivot field, an additionalProperties entry at most,
        // renders none. A member with no row has no stored values to render.
        if ($this->relation->pivotFields() === []) {
            return $meta;
        }
        if ($pivot === null && $this->relation->storedPivotFields() !== []) {
            throw MissingPivotRow::for(
                $this->source->parentType ?? 'unknown',
                $this->relation->name(),
                $this->source->parentId ?? 'unknown',
                $id,
                $this->source->provider ?? 'its provider',
            );
        }
        // A hook reads every stored value in its wire form, a date in the server's zone,
        // whichever provider read it.
        $pivot = $this->wireValues($pivot ?? []);
        if ($this->parent !== null && PivotFields::readsThroughHook($this->relation)) {
            $pivot = [...$pivot, ...$this->relation->hookedPivotValues($this->parent, $object, $pivot, $request, $this->outputTimezone)];
        }

        return $this->withObject($meta, $this->readable($pivot, $request, $object));
    }

    /**
     * `$pivot` with each stored date cast to its wire form in the server's output zone, as
     * the Doctrine provider reads it, for a provider that hands its dates over as stored.
     *
     * @param array<string, mixed> $pivot
     *
     * @return array<string, mixed>
     */
    private function wireValues(array $pivot): array
    {
        if (!$this->relation instanceof BelongsToMany) {
            return $pivot;
        }
        foreach ($pivot as $name => $value) {
            $field = $value instanceof \DateTimeInterface ? $this->relation->pivotField($name) : null;
            if ($field !== null) {
                $pivot[$name] = PivotFields::cast($value, $field, $this->outputTimezone);
            }
        }

        return $pivot;
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

        return $this->withObject($meta, $pivot);
    }

    /**
     * `$meta` with `$pivot` under `pivot`, an empty object when every field is hidden for
     * the request, as the response pivot schema requires the member.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $pivot
     *
     * @return array<string, mixed>
     */
    private function withObject(array $meta, array $pivot): array
    {
        $meta['pivot'] = $pivot === [] ? new \stdClass() : $pivot;

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
            $value = $pivot[$field->name()] ?? null;
            // A provider that leaves a stored date as it is, the in-memory one, gets it cast in
            // the server's output zone here, as the Doctrine provider casts its own.
            $readable[$field->name()] = $value instanceof \DateTimeInterface ? PivotFields::cast($value, $field, $this->outputTimezone) : $value;
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
}
