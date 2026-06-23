<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The convenience on-ramp for a custom {@see DataProviderInterface}: an abstract base
 * that leaves the irreducible read core — {@see supports()}, {@see fetchOne()},
 * {@see fetchCollection()} — abstract, and supplies **neutral default bodies** for the
 * six relationship / batch / pivot seams a bare interface forces every implementor to
 * hand-stub.
 *
 * Each default is the value the *caller* treats as "this capability is absent": a
 * relation a provider serves no related collection for, no counts for, no pivot for. So
 * a thin provider — one that serves a flat collection with no relationships, or
 * delegates the rest to another provider — extends this and writes only the three
 * abstracts, instead of stubbing all nine methods (PHP interfaces carry no bodies, so
 * the bare {@see DataProviderInterface} makes every method mandatory). It is the
 * read-side analogue of core's `ResourceLifecycleHooksTrait`: no-op convenience, opt
 * out by overriding.
 *
 * **A type whose relations are actually served must override the relevant seam.** In
 * particular a type whose **to-one relations are filtered** (the related/relationship
 * endpoints accept `?filter`, or the type participates in a `relatedQuery[<toOne>][filter]`
 * include) must override {@see relatedToOneMatches()} *and* {@see relatedToOneMatchesBatch()}
 * so an excluded target is nulled — the defaults here deliberately match **every** target
 * (they never null), since a base provider that does not know how to filter a to-one must
 * not silently drop one.
 *
 * @template-covariant TEntity of object
 *
 * @implements DataProviderInterface<TEntity>
 */
abstract class AbstractDataProvider implements DataProviderInterface
{
    abstract public function supports(string $type): bool;

    /**
     * @return TEntity|null
     */
    abstract public function fetchOne(string $type, string $id): ?object;

    /**
     * @return CollectionResult<TEntity>
     */
    abstract public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult;

    /**
     * Default: an **empty, unwindowed** {@see CollectionResult} — the provider serves no
     * related collection for `$relation` (the related endpoint renders an empty
     * collection: no members, and — since the result is not windowed — no pagination
     * meta/links). Override to serve a to-many related endpoint.
     *
     * @return CollectionResult<TEntity>
     */
    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return new CollectionResult([]);
    }

    /**
     * Default: an **empty {@see RelatedBatch}** — no parent has any related members in
     * the batch, so {@see RelatedBatch::for()} fills every parent with its own empty
     * result and the include preloader loads this relation lazily instead. This is the
     * exact "a relation the provider cannot batch" contract value (a computed column, a
     * non-association). Override to batch a to-many include without N+1.
     */
    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        return new RelatedBatch([]);
    }

    /**
     * Default: the **empty count map** `[]` — the provider reports no relationship
     * cardinalities. The caller treats a parent absent from the map as a count it cannot
     * supply, so `?withCount` / a countable relation simply omits the count. Override to
     * support `?withCount` (a grouped, pushed-down count keyed by parent wire id).
     *
     * @return array<int|string, int>
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        return [];
    }

    /**
     * Default: **`true`** — the single related target always matches, so a filtered
     * to-one is **never nulled** by this provider. Returning `false` here would null a
     * *matching* to-one, so the absent-capability value is `true` (the contract's "a
     * provider with no to-one filter support nulls nothing extra"). A type whose to-one
     * relations ARE filtered must override this to actually probe the target.
     */
    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        return true;
    }

    /**
     * Default: the **all-match map** — every parent mapped to `true`, so no parent's
     * to-one is nulled. An empty `[]` would null **every** parent (the caller treats a
     * parent absent from the map as a no-match), so the absent-capability value is the
     * full all-`true` map, mirroring {@see relatedToOneMatches()}'s `true`.
     *
     * Keyed by each parent's wire id, resolved through {@see Accessor::get()} on the
     * conventional `id` member — the same value the default serializer's `getId()`
     * reports for the common case (a plain `id` field, no id encoder). A type with a
     * non-`id` id member, an id encoder, or an actually-filtered to-one MUST override
     * this (and {@see relatedToOneMatches()}) so the map keys — and the filter probe —
     * match the serializer exactly.
     *
     * @return array<string, bool>
     */
    public function relatedToOneMatchesBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        $matches = [];
        foreach ($parents as $parent) {
            $wireId = Accessor::get($parent, 'id');
            if (!\is_scalar($wireId)) {
                continue;
            }

            $matches[(string) $wireId] = $this->relatedToOneMatches(
                $relation->relatedTypes()[0] ?? $parentType,
                $parent,
                $relation,
                $criteria,
                $request,
            );
        }

        return $matches;
    }

    /**
     * Default: **`[]`** — the provider stores no pivot meta, so every incoming
     * relationship member validates in the create (new-row) context. This is the
     * documented boundary for a non-pivot relation, a pivot relation with no pivot
     * fields, or a store that cannot read pivot data. Override to fold a stored pivot row
     * under an incoming member's meta on an update.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return [];
    }
}
