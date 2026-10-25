<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Decides which provider loads a relation's members for a document (includes,
 * linkage, `?withCount`, the Relationship Queries window), so they are the members
 * the related endpoint serves (bundle ADR 0125).
 *
 * The related endpoint reads through the provider of the relation's first related
 * type. A relation is **scoped** when that provider may return fewer members than the
 * parent model holds: it declares so through {@see ScopesRelatedReadsInterface}, or
 * it does not implement that interface. A scoped relation whose related provider is
 * not the parent type's provider is read per parent through the related provider, as
 * the endpoint does. Otherwise the parent type's provider batches the whole page.
 *
 * @internal
 */
final class RelatedReadRouter
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    /**
     * Whether the provider serving `$relation`'s related endpoint scopes its reads. A
     * related type with no provider has nothing to scope by.
     */
    public function isScoped(RelationInterface $relation): bool
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$this->providers->supportsType($relatedType)) {
            return false;
        }

        $provider = $this->providers->forType($relatedType);

        return !$provider instanceof ScopesRelatedReadsInterface || $provider->scopesRelatedReads($relation);
    }

    /**
     * Whether the provider of `$type` scopes its reads of `$relation`, for a
     * polymorphic relation whose target renders as `$type`.
     */
    public function isScopedType(string $type, RelationInterface $relation): bool
    {
        if (!$this->providers->supportsType($type)) {
            return false;
        }

        $provider = $this->providers->forType($type);

        return !$provider instanceof ScopesRelatedReadsInterface || $provider->scopesRelatedReads($relation);
    }

    /**
     * Whether `$relation` is a pivot `belongsToMany` its related provider serves through
     * an association entity.
     */
    public function isPivot(RelationInterface $relation): bool
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$relation->isToMany() || !$this->providers->supportsType($relatedType)) {
            return false;
        }

        $provider = $this->providers->forType($relatedType);

        return $provider instanceof PivotAwareProviderInterface && $provider->supportsPivot($relatedType, $relation);
    }

    /**
     * Whether `$relation`'s members must be read per parent through the related
     * endpoint's provider rather than batched by the parent type's provider.
     */
    public function readsThroughRelatedProvider(string $type, RelationInterface $relation): bool
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$this->providers->supportsType($relatedType) || !$this->isScoped($relation)) {
            return false;
        }

        return \count($relation->relatedTypes()) > 1
            || !$this->providers->supportsType($type)
            || $this->providers->forType($type) !== $this->providers->forType($relatedType);
    }

    /**
     * Loads `$relation` for every parent per `$criteria`, keyed by parent wire id.
     *
     * @param list<object> $parents
     */
    public function batch(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        if (!$this->readsThroughRelatedProvider($type, $relation)) {
            $relatedType = $relation->relatedTypes()[0] ?? $type;

            return $this->providers->forType($this->providers->supportsType($type) ? $type : $relatedType)
                ->fetchRelatedCollectionBatch($type, $parents, $relation, $criteria, $request);
        }

        $relatedType = $relation->relatedTypes()[0];
        $provider = $this->providers->forType($relatedType);
        $serializer = $server->serializerFor($type);

        $results = [];
        foreach ($parents as $parent) {
            if ($relation->isToMany()) {
                // The pivot query when the related endpoint would run it, as it does.
                $results[$serializer->getId($parent)] = $provider instanceof PivotAwareProviderInterface && $provider->supportsPivot($relatedType, $relation)
                    ? $provider->fetchRelatedPivotCollection($relatedType, $parent, $relation, $criteria, $request)
                    : $provider->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);

                continue;
            }

            // The provider's to-one probe applies its scope to the target the parent holds.
            $target = $relation->readValue($parent, $request);
            $matches = \is_object($target) && $provider->relatedToOneMatches($relatedType, $target, $relation, $criteria, $request);
            $results[$serializer->getId($parent)] = new CollectionResult($matches ? [$target] : []);
        }

        return new RelatedBatch($results);
    }

    /**
     * The `?withCount` total of `$relation` per parent wire id, over the members the
     * related endpoint serves.
     *
     * @param list<object> $parents
     *
     * @return array<int|string, int>
     */
    public function counts(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        if (!$this->readsThroughRelatedProvider($type, $relation)) {
            return $this->providers->forType($type)->countRelated($type, $parents, $relation, $criteria, $request);
        }

        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $provider = $this->providers->forType($relatedType);
        $serializer = $server->serializerFor($type);

        // The pivot query when the related endpoint would run it, so the count is of the
        // same rows.
        $pivot = $provider instanceof PivotAwareProviderInterface && $provider->supportsPivot($relatedType, $relation);

        $counts = [];
        foreach ($parents as $parent) {
            $result = $pivot
                ? $provider->fetchRelatedPivotCollection($relatedType, $parent, $relation, $criteria, $request)
                : $provider->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
            $counts[$serializer->getId($parent)] = \is_array($result->items) ? \count($result->items) : \iterator_count($result->items);
        }

        return $counts;
    }

    /**
     * Whether each parent's to-one target survives `$criteria` (and the related
     * provider's scope), keyed by parent wire id.
     *
     * @param list<object> $parents
     *
     * @return array<string, bool>
     */
    public function toOneMatches(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        if (!$this->readsThroughRelatedProvider($type, $relation)) {
            return $this->providers->forType($type)->relatedToOneMatchesBatch($type, $parents, $relation, $criteria, $request);
        }

        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $provider = $this->providers->forType($relatedType);
        $serializer = $server->serializerFor($type);

        $matches = [];
        foreach ($parents as $parent) {
            $target = $relation->readValue($parent, $request);
            $matches[$serializer->getId($parent)] = \is_object($target)
                && $provider->relatedToOneMatches($relatedType, $target, $relation, $criteria, $request);
        }

        return $matches;
    }
}
