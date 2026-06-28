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
 * `?include`) carries pivot exactly as the related/relationship endpoints already do
 * (bundle ADR 0102).
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
 */
final class PivotLinkageParentSerializer extends AbstractPivotParentSerializer
{
    /**
     * @param SerializerInterface              $inner     the real parent serializer
     * @param SerializerResolverInterface      $resolver  the base resolver (the Server)
     * @param array<string, RelationInterface> $relations the pivot relations to rebind, keyed by relation name
     * @param array<string, array<string, array<string, array<string, mixed>>>> $maps the batched per-parent pivot maps, `relationName => parentWireId => memberId => [field => value]`
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly SerializerResolverInterface $resolver,
        private readonly array $relations,
        private readonly array $maps,
    ) {}

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);

        $parentWireId = $this->inner->getId($object);

        foreach ($this->relations as $name => $relation) {
            // Only rebind a relation the INNER serializer actually rendered: core's
            // AbstractResource::getRelationships() excludes a per-request
            // conditionally-hidden relation (isHiddenFor), but the selection step
            // (CrudOperationHandler::withPivotLinkage) only filters UNCONDITIONALLY
            // hidden relations, so a `hidden(fn …)` pivot relation with rendered data
            // would otherwise be RE-ADDED here — leaking a relationship the author hid
            // for this request (bundle ADR 0102). Respect the inner decision.
            if (!\array_key_exists($name, $relationships)) {
                continue;
            }

            $relatedType = $relation->relatedTypes()[0] ?? null;
            if ($relatedType === null || !$this->resolver->hasSerializerFor($relatedType)) {
                continue;
            }

            // The slice of the batched map for THIS parent, keyed by member id so the
            // PivotMetaSerializer looks up only the members the linkage renders (and
            // composes with any windowing/filtering for free). Empty when this parent
            // has no association rows.
            $mapForThisParent = $this->maps[$name][$parentWireId] ?? [];

            $relationships[$name] = $this->pivotLinkageBuilder(
                $relation,
                $this->resolver,
                new PivotMetaSerializer($this->resolver->serializerFor($relatedType), $mapForThisParent),
            );
        }

        return $relationships;
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }
}
