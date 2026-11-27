<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Resource\Internal\NonSparseMember;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
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
     * @param array<string, array<string, array<string, array<string, mixed>>>|null> $maps the batched per-parent pivot maps, `relationName => parentWireId => memberId => [field => value]`, `null` for a relation with no pivot storage, which renders its computed fields only
     * @param string|null                      $outputTimezone the server's default output zone a computed pivot date field adopts
     * @param array<string, array{relation: RelationInterface, serializers: non-empty-array<string, SerializerInterface>}> $nested the included relations whose related resources render through their own decorators, keyed by relation name, with those decorators keyed by related type
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly SerializerResolverInterface $resolver,
        private readonly array $relations,
        private readonly array $maps,
        private readonly ?string $outputTimezone = null,
        private readonly array $nested = [],
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
            // composes with any windowing/filtering for free). Empty when this parent
            // has no association rows.
            $map = $this->maps[$name] ?? null;
            $mapForThisParent = $map === null ? [] : $map[$parentWireId] ?? $this->comparableEntry($map, $parentWireId);

            // A member whose own relations render pivots further down renders through
            // the decorator for its include path.
            $related = $this->nested[$name]['serializers'][$relatedType] ?? $this->resolver->serializerFor($relatedType);

            $relationships[$name] = self::rebound($relationships[$name], $this->pivotLinkageBuilder(
                $relation,
                $this->resolver,
                new PivotMetaSerializer($related, $mapForThisParent, $relation, \is_object($object) ? $object : null, $this->outputTimezone, computedOnly: $map === null),
            ));
        }

        foreach ($this->nested as $name => $nested) {
            if (isset($this->relations[$name]) || !\array_key_exists($name, $relationships)) {
                continue;
            }

            $relationships[$name] = self::rebound($relationships[$name], $this->reboundBuilder($nested['relation'], $this->resolver, $nested['serializers']));
        }

        return $relationships;
    }

    /**
     * `$build` in place of the inner serializer's `$callable`, still rendered whatever
     * `fields[type]` lists when the relation is declared `notSparseField()`.
     *
     * @param \Closure(mixed, JsonApiRequestInterface, string): AbstractRelationship $build
     *
     * @return callable(mixed, JsonApiRequestInterface, string): AbstractRelationship
     */
    private static function rebound(mixed $callable, \Closure $build): callable
    {
        return $callable instanceof NonSparseMember ? new NonSparseMember($build) : $build;
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }

    /**
     * The entry of `$map` whose key is `$wireId` in another case, as a uuid-shaped id a
     * provider keyed in capitals is.
     *
     * @param array<string, array<string, array<string, mixed>>> $map
     *
     * @return array<string, array<string, mixed>>
     */
    private function comparableEntry(array $map, string $wireId): array
    {
        $wanted = StoredIdForms::comparable($wireId);
        foreach ($map as $key => $entry) {
            if (StoredIdForms::comparable($key) === $wanted) {
                return $entry;
            }
        }

        return [];
    }
}
