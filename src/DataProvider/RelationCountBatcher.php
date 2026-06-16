<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\BatchedRelationshipCount;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Batches the cardinality of every `?withCount`-named countable to-many relation
 * across an already-fetched page of parents, so a collection render of
 * `meta.total` does not N+1 (bundle ADR 0052). It mirrors the
 * {@see RelatedIncludeBatcher}'s batch-by-page architecture: it runs once, before
 * render, over the page the
 * handler already materialized, and asks the provider for ONE grouped count per
 * relation ({@see DataProviderInterface::countRelated()}) rather than one count per
 * parent.
 *
 * The requested countable relations are the intersection of the request's flat
 * `?withCount` list ({@see JsonApiRequestInterface::getCountedRelationships()}) and
 * the type's declared to-many relations marked {@see RelationInterface::isCountable()}
 * — core has already validated `?withCount` up front (a non-countable or to-one
 * name `400`s before the handler runs), so this only ever counts a sound set; a
 * name the request did not ask for, or a non-countable relation, is simply not
 * batched.
 *
 * Each count is over the relation's **filtered** set: the batcher builds a
 * filters-only {@see CollectionCriteria} per relation through the shared
 * {@see RelationCriteriaFactory} (the same merge the related endpoint uses) from the
 * request's `relatedQuery[<rel>][filter]`, and hands it to
 * {@see DataProviderInterface::countRelated()} — so a `?withCount`-named relation that
 * also carries a relatedQuery filter counts the SAME set the related-collection
 * endpoint would page (bundle ADR 0060). In the common no-relatedQuery case the
 * criteria is empty and the count is raw membership unchanged.
 *
 * The provider returns each relation's counts keyed by the parent's wire id; this
 * batcher resolves each parent's wire id through the primary serializer
 * ({@see \haddowg\JsonApi\Serializer\SerializerInterface::getId()}) and re-keys the
 * result by the parent's object identity, producing a
 * {@see BatchedRelationshipCount} the handler injects into core's count seam for
 * the render pass.
 */
final class RelationCountBatcher
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
        private readonly RelationCriteriaFactory $relationCriteria,
    ) {}

    /**
     * Builds the per-page count seam for the `?withCount`-named countable relations
     * of `$type`, or `null` when the request named none (so the handler skips the
     * injection entirely and renders exactly as before).
     *
     * @param list<object> $parents the already-fetched page of parents
     */
    public function batch(Server $server, string $type, array $parents, JsonApiRequestInterface $request): ?BatchedRelationshipCount
    {
        if ($parents === []) {
            return null;
        }

        $requested = $request->getCountedRelationships();
        if ($requested === []) {
            return null;
        }

        $relations = $this->countableRelations($server, $type, $requested);
        if ($relations === []) {
            return null;
        }

        $provider = $this->providers->forType($type);
        $serializer = $server->serializerFor($type);

        // Resolve each parent's wire id once (the provider keys its counts by it),
        // and index the parents by wire id so a provider count maps back to the
        // object whose identity the render seam keys on.
        $byWireId = [];
        foreach ($parents as $parent) {
            $byWireId[$serializer->getId($parent)][] = $parent;
        }

        $counts = [];
        foreach ($relations as $relation) {
            $criteria = $this->countCriteria($server, $relation, $request);

            $relationCounts = $provider->countRelated($type, $parents, $relation, $criteria, $request);
            foreach ($relationCounts as $wireId => $count) {
                foreach ($byWireId[(string) $wireId] ?? [] as $parent) {
                    $counts[\spl_object_id($parent)][$relation->name()] = $count;
                }
            }
        }

        return $counts === [] ? null : new BatchedRelationshipCount($counts);
    }

    /**
     * The filters-only {@see CollectionCriteria} for one `?withCount`-named relation:
     * the relation's `relatedQuery[<rel>][filter]` resolved against the merged
     * related-collection filter vocabulary, with NO window (a count needs no page) and
     * NO sort (order is irrelevant to a count) — exactly the filter half of the
     * criteria {@see DataProviderInterface::fetchRelatedCollection()} applies (bundle
     * ADR 0060). Built through the same {@see RelationCriteriaFactory} the related
     * endpoint and the include-window batcher use, so the count honours the identical
     * vocabulary merge (related resource ⊕ relation scope) and the same unknown-key
     * `400`. In the common no-relatedQuery case the request's filter is empty, so the
     * criteria is inert and the count is raw membership unchanged.
     *
     * A polymorphic relation has no single related resource, so the merge collapses to
     * the relation's own scoped vocabulary (the Doctrine provider throws for a
     * polymorphic to-many before any criteria is applied; the in-memory witness applies
     * whatever the relation declares).
     *
     * The criteria the factory builds carries the related resource's `defaultSort()`, but
     * a count needs no order — and that default would be wrong on the Doctrine count
     * query, which roots on the PARENT (the related resource's default-sort column lives
     * on the joined `related` entity, not the parent root, so an unguarded default `ORDER
     * BY` on the grouped count would name a parent column that does not exist). So the
     * count criteria is rebuilt with an empty `defaultSort`: sort is dropped on both
     * providers (in-memory sorts an array it then only counts; Doctrine emits no `ORDER
     * BY`), keeping the count crash-free and witness-identical regardless of the related
     * resource's default order (bundle ADR 0060).
     */
    private function countCriteria(Server $server, RelationInterface $relation, JsonApiRequestInterface $request): CollectionCriteria
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        $relatedResource = $relatedType !== null && \count($relation->relatedTypes()) === 1
            ? $this->types->resourceFor($server, $relatedType)
            : null;

        $relatedQuery = $request->getRelatedQuery($relation->name());

        $criteria = $this->relationCriteria->criteriaFor(
            new QueryParameters(
                fields: [],
                includes: [],
                sort: [],
                filter: $relatedQuery->filter,
                pagination: $request->getPagination(),
            ),
            $relatedResource,
            $relation,
            null,
            includePivotFields: false,
        );

        // Drop the related resource's default order — a count needs none, and on the
        // Doctrine count it would resolve a related-entity column against the parent root.
        return new CollectionCriteria(
            $criteria->queryParameters,
            $criteria->filters,
            $criteria->sorts,
            $criteria->window,
            defaultSort: [],
            aliasOf: $criteria->aliasOf,
        );
    }

    /**
     * The declared to-many countable relations of `$type` whose name appears in
     * `$requested` (the `?withCount` list). Core has already rejected a name that is
     * not countable / not to-many, so this filter is belt-and-braces: it never
     * counts a relation the request did not name, and never a non-countable one.
     *
     * @param list<string> $requested
     *
     * @return list<RelationInterface>
     */
    private function countableRelations(Server $server, string $type, array $requested): array
    {
        $wanted = \array_flip($requested);

        $relations = [];
        foreach ($this->types->relationsFor($server, $type) as $relation) {
            if ($relation->isToMany()
                && $relation->isCountable()
                && isset($wanted[$relation->name()])) {
                $relations[] = $relation;
            }
        }

        return $relations;
    }
}
