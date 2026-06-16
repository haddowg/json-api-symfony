<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\BatchedRelationshipCount;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Batches the cardinality of every `?withCount`-named countable to-many relation
 * across an already-fetched page of parents, so a collection render of
 * `meta.total` does not N+1 (bundle ADR 0052). It mirrors the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\IncludePreloader}'s
 * batch-by-page architecture: it runs once, before render, over the page the
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
            $relationCounts = $provider->countRelated($type, $parents, $relation, $request);
            foreach ($relationCounts as $wireId => $count) {
                foreach ($byWireId[(string) $wireId] ?? [] as $parent) {
                    $counts[\spl_object_id($parent)][$relation->name()] = $count;
                }
            }
        }

        return $counts === [] ? null : new BatchedRelationshipCount($counts);
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
