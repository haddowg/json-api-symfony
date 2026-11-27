<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A parent-serializer decorator that makes a **primary-resource document**'s
 * relationships block render each `belongsToMany` pivot relation's per-member pivot
 * values as identifier `meta.pivot`, wherever that relation's linkage DATA renders
 * (an `?include`d relation, or one that renders data by default) — so a primary
 * read (`GET /playlists/1?include=tracks`, or a default-data relation with no
 * `?include`) carries pivot exactly as the related/relationship endpoints already do.
 *
 * It is the multi-relation, per-parent twin of {@see PivotParentSerializer} (which
 * rebinds ONE relation over the WHOLE association's map for the relationship-linkage
 * endpoint): here several pivot relations may render at once, across a PAGE of
 * parents, so it holds a set of relations plus a BATCHED per-parent pivot map
 * (`relationName => parentWireId => memberId => [field => value]`) and, for the
 * object being serialized, slices each relation's map to the parent's own entry
 * (keyed by the inner serializer's `getId()`). Every other method delegates to the
 * inner serializer (including every optional serializer-render interface, via
 * {@see AbstractPivotParentSerializer}), so a relation with no pivot map renders
 * untouched.
 *
 * Keying the per-parent map by member id means it composes with any windowing or
 * filtering core applies to the linkage: the {@see PivotMetaSerializer} looks up only
 * the members it actually renders and ignores the rest.
 *
 * The same decorator renders an included resource's relations, one per include path:
 * a relation in `$nested` renders its related resources through the serializers given
 * for their types, each itself a decorator for the next path down, so a pivot relation
 * reached through a nested include (`?include=crew.members`) carries its pivot too.
 */
final class PivotLinkageParentSerializer extends AbstractPivotParentSerializer
{
    /**
     * @param SerializerInterface              $inner     the real parent serializer
     * @param SerializerResolverInterface      $resolver  the base resolver (the Server)
     * @param array<string, RelationInterface> $relations the pivot relations to rebind, keyed by relation name
     * @param array<string, PivotMapBatch|null> $maps the batched per-parent pivot maps keyed by relation name, `null` for a relation with no pivot storage, which renders its computed fields only
     * @param string|null                      $outputTimezone the server's default output zone a computed pivot date field adopts
     * @param array<string, array{relation: RelationInterface, serializers: non-empty-array<string, SerializerInterface>}> $nested the included relations whose related resources render through their own decorators, keyed by relation name, with those decorators keyed by related type
     * @param string|null                      $type      the one type whose resources this decorator rebinds when the inner serializer renders several; a resource of another type renders as the inner serializer renders it
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly SerializerResolverInterface $resolver,
        private readonly array $relations,
        private readonly array $maps,
        private readonly ?string $outputTimezone = null,
        private readonly array $nested = [],
        private readonly ?string $type = null,
    ) {}

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);
        if ($this->type !== null && $this->inner->getType($object) !== $this->type) {
            return $relationships;
        }

        $parentWireId = $this->inner->getId($object);

        foreach ($this->relations as $name => $relation) {
            // Only rebind a relation the INNER serializer actually rendered: core's
            // AbstractResource::getRelationships() excludes a per-request
            // conditionally-hidden relation (isHiddenFor), but the selection step
            // (CrudOperationHandler::withPivotLinkage) only filters UNCONDITIONALLY
            // hidden relations, so a `hidden(fn …)` pivot relation with rendered data
            // would otherwise be RE-ADDED here — leaking a relationship the author hid
            // for this request. Respect the inner decision.
            if (!\array_key_exists($name, $relationships)) {
                continue;
            }

            $relatedType = $relation->relatedTypes()[0] ?? null;
            if ($relatedType === null || !$this->resolver->hasSerializerFor($relatedType)) {
                continue;
            }

            // The slice of the batched map for THIS parent, keyed by member id so the
            // PivotMetaSerializer looks up only the members the linkage renders (and
            // composes with any windowing/filtering for free). The batch is read only
            // when a member's pivot renders, so a links-only linkage reads nothing.
            $map = $this->maps[$name] ?? null;
            $mapForThisParent = $map === null ? [] : static fn(): array => $map->forParent($parentWireId);

            // A member whose own relations render pivots further down renders through
            // the decorator for its include path.
            $related = $this->nested[$name]['serializers'][$relatedType] ?? $this->resolver->serializerFor($relatedType);

            $relationships[$name] = $this->pivotLinkageBuilder(
                $relation,
                $this->resolver,
                new PivotMetaSerializer($related, $mapForThisParent, $relation, \is_object($object) ? $object : null, $this->outputTimezone, computedOnly: $map === null, source: $map === null ? null : new PivotRowSource($this->inner->getType($object), $parentWireId, $map->provider)),
            );
        }

        foreach ($this->nested as $name => $nested) {
            if (isset($this->relations[$name]) || !\array_key_exists($name, $relationships)) {
                continue;
            }

            $relationships[$name] = $this->reboundBuilder($nested['relation'], $this->resolver, $nested['serializers']);
        }

        return $relationships;
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }
}
