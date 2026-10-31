<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Decides which provider loads a relation's members for a document (includes,
 * linkage, `?withCount`, the Relationship Queries window), so they are the members
 * the related endpoint serves (bundle ADR 0035).
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
     * Whether the provider of any type `$relation` reaches scopes its reads, so a
     * polymorphic relation is scoped when any of its types is. A related type with no
     * provider has nothing to scope by.
     */
    public function isScoped(RelationInterface $relation): bool
    {
        foreach ($relation->relatedTypes() as $relatedType) {
            if ($this->isScopedType($relatedType, $relation)) {
                return true;
            }
        }

        return false;
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
        if ($this->readsComputed($relation)) {
            $serializer = $server->serializerFor($type);
            $results = [];
            foreach ($this->admitComputed($server, $parents, $relation, $request) as $index => $members) {
                $results[$serializer->getId($parents[$index])] = $this->queryMembers($relation, $members, $criteria);
            }

            return new RelatedBatch($results);
        }

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
     * Each parent's target for a `MorphTo` declared with `identifiedBy()`, loaded from
     * the stored pairs with one {@see FetchesByIdsInterface::fetchByIds()} per type
     * (bundle ADR 0035), limited to `$types` when given. An entry is `null` for an empty
     * pair. `target` is `null` when the type's provider doesn't return the row.
     * `loaded` is `false` for a type left out of `$types` or that no provider serves.
     *
     * @param list<object>      $parents
     * @param list<string>|null $types
     *
     * @return list<array{type: string, target: ?object, loaded: bool}|null>
     *
     * @throws \LogicException when a parent stores a type the relation doesn't declare
     */
    public function identifiedTargets(Server $server, MorphTo $relation, array $parents, JsonApiRequestInterface $request, ?array $types = null): array
    {
        $rows = [];
        foreach ($relation->storedIdentifiers($parents, $request) as $type => $ids) {
            if (($types !== null && !\in_array($type, $types, true)) || !$server->hasSerializerFor($type) || !$this->providers->supportsType($type)) {
                continue;
            }

            $serializer = $server->serializerFor($type);
            $rows[$type] = $this->fetchByIds(
                $type,
                $serializer,
                \array_values(\array_unique(\array_map(static fn(string|int $id): string => self::wireId($serializer, $id), $ids))),
                $request,
            );
        }

        $targets = [];
        foreach ($parents as $parent) {
            $pair = $relation->storedIdentifier($parent, $request);
            if ($pair === null) {
                $targets[] = null;

                continue;
            }

            [$type, $id] = $pair;
            $targets[] = isset($rows[$type])
                ? ['type' => $type, 'target' => $rows[$type][self::wireId($server->serializerFor($type), $id)] ?? null, 'loaded' => true]
                : ['type' => $type, 'target' => null, 'loaded' => false];
        }

        return $targets;
    }

    /**
     * The rows of `$type` with the wire ids `$ids` that its provider admits, keyed by
     * wire id.
     *
     * @param list<string> $ids
     *
     * @return array<string, object>
     */
    private function fetchByIds(string $type, SerializerInterface $serializer, array $ids, JsonApiRequestInterface $request): array
    {
        $provider = $this->providers->forType($type);
        if ($provider instanceof FetchesByIdsInterface) {
            $rows = $provider->fetchByIds($type, $ids, $request);
        } else {
            $rows = [];
            foreach ($ids as $id) {
                $row = $provider->fetchOne($type, $id);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[$serializer->getId($row)] = $row;
        }

        return $byId;
    }

    /**
     * A stored key as `$serializer` renders it: through its id field's encoder when it
     * declares one, as core renders the pair's linkage.
     */
    private static function wireId(SerializerInterface $serializer, string|int $id): string
    {
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    return $field->encoder()?->encode($id) ?? (string) $id;
                }
            }
        }

        return (string) $id;
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
        if ($this->readsComputed($relation)) {
            $serializer = $server->serializerFor($type);
            $counts = [];
            foreach ($this->admitComputed($server, $parents, $relation, $request) as $index => $members) {
                $items = $this->queryMembers($relation, $members, $criteria)->items;
                $counts[$serializer->getId($parents[$index])] = \is_array($items) ? \count($items) : \iterator_count($items);
            }

            return $counts;
        }

        // A polymorphic to-many reaching a scoped type counts the members its related
        // endpoint would page through, narrowed per member type.
        if ($relation->isToMany() && \count($relation->relatedTypes()) > 1 && $this->isScoped($relation)) {
            $serializer = $server->serializerFor($type);
            $counts = [];
            foreach ($parents as $parent) {
                $items = $this->fetchPolymorphic($server, $parent, $relation, $criteria, $request)->items;
                $counts[$serializer->getId($parent)] = \is_array($items) ? \count($items) : \iterator_count($items);
            }

            return $counts;
        }

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

    /**
     * Whether `$relation`'s value comes from an `extractUsing()` closure. Core keeps the
     * closure on the field's protected state, so this reads it by reflection. A pivot
     * `belongsToMany` is not counted: its closure only feeds core's model read, and the
     * provider's pivot query is what its related endpoint serves.
     */
    public function isComputed(RelationInterface $relation): bool
    {
        if (!$relation instanceof AbstractFieldValue || $this->isPivot($relation)) {
            return false;
        }

        static $state = null;
        $state ??= new \ReflectionProperty(AbstractFieldValue::class, 'state');
        $fieldState = $state->getValue($relation);

        return \is_object($fieldState) && \property_exists($fieldState, 'extractUsing') && $fieldState->extractUsing !== null;
    }

    /**
     * Whether `$relation` is computed and any type it relates to is scoped, so its
     * members are the closure's value narrowed to the rows that type's provider admits
     * (bundle ADR 0035). An unscoped computed relation renders the closure's value as is.
     */
    public function readsComputed(RelationInterface $relation): bool
    {
        if (!$this->isComputed($relation)) {
            return false;
        }

        foreach ($relation->relatedTypes() as $type) {
            if ($this->isScopedType($type, $relation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A computed relation's members on `$parent`, narrowed to the scope and run through
     * `$criteria`: the related endpoint's page.
     *
     * @return CollectionResult<object>
     */
    public function fetchComputed(Server $server, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->queryMembers($relation, $this->admitComputed($server, [$parent], $relation, $request)[0], $criteria);
    }

    /**
     * A computed to-one's target on `$parent`, or `null` when the scope hides it.
     */
    public function computedTarget(Server $server, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): ?object
    {
        return $this->admitComputed($server, [$parent], $relation, $request)[0][0] ?? null;
    }

    /**
     * Each parent's computed members (the closure's value), narrowed to the scope.
     *
     * @param list<object> $parents
     *
     * @return list<list<object>>
     */
    private function admitComputed(Server $server, array $parents, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        return $this->admitMembers($server, $parents, $relation, $request);
    }

    /**
     * Each parent's members as its model holds them, keeping only the rows the provider
     * of their type admits: the members are grouped by type and each scoped type is asked
     * once across all the parents, before any criteria run, so pages and counts are of the
     * narrowed set. Rows of an unscoped type are kept without a probe.
     *
     * @param list<object> $parents
     *
     * @return list<list<object>>
     */
    public function admitMembers(Server $server, array $parents, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $candidates = [];
        foreach ($parents as $parent) {
            $value = $relation->readValue($parent, $request);
            $candidates[] = \is_iterable($value) ? $this->objects($value) : (\is_object($value) ? [$value] : []);
        }

        return $this->narrow($server, $candidates, $relation, $request);
    }

    /**
     * A polymorphic to-many's related page: the members its first type's provider loads,
     * unpaged, narrowed per member type to the rows each scoped type's provider admits,
     * then paged and counted, so the page is full and its total is of the narrowed set.
     *
     * @return CollectionResult<object>
     */
    public function fetchPolymorphic(Server $server, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        if ($this->readsComputed($relation)) {
            return $this->fetchComputed($server, $parent, $relation, $criteria, $request);
        }

        $relatedType = $relation->relatedTypes()[0] ?? '';
        $unpaged = new CollectionCriteria($criteria->queryParameters, $criteria->filters, $criteria->sorts, null, $criteria->defaultSort, $criteria->aliasOf);
        $result = $this->providers->forType($relatedType)->fetchRelatedCollection($relatedType, $parent, $relation, $unpaged, $request);

        return $this->queryMembers($relation, $this->narrow($server, [$this->objects($result->items)], $relation, $request)[0], $criteria);
    }

    /**
     * @param list<list<object>> $candidates each parent's candidate members
     *
     * @return list<list<object>>
     */
    private function narrow(Server $server, array $candidates, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $types = $relation->relatedTypes();
        $byType = [];
        foreach ($candidates as $members) {
            foreach ($members as $member) {
                $type = \count($types) === 1 ? $types[0] : $relation->resolveSerializer($member, $server)?->getType($member);
                if ($type !== null) {
                    $byType[$type][\spl_object_id($member)] = $member;
                }
            }
        }

        $admitted = [];
        foreach ($byType as $type => $rows) {
            foreach ($this->admitRows($server, $type, \array_values($rows), $relation, $request) as $row) {
                $admitted[\spl_object_id($row)] = true;
            }
        }

        return \array_map(
            static fn(array $members): array => \array_values(\array_filter($members, static fn(object $member): bool => isset($admitted[\spl_object_id($member)]))),
            $candidates,
        );
    }

    /**
     * @param iterable<mixed> $value
     *
     * @return list<object>
     */
    private function objects(iterable $value): array
    {
        $objects = [];
        foreach ($value as $member) {
            if (\is_object($member)) {
                $objects[] = $member;
            }
        }

        return $objects;
    }

    /**
     * The rows of `$type` its provider's scope admits: all of them when it doesn't scope,
     * one probe when it can say so in one, and otherwise one `fetchOne()` per row.
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    private function admitRows(Server $server, string $type, array $rows, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        if (!$this->isScopedType($type, $relation)) {
            return $rows;
        }

        $provider = $this->providers->forType($type);
        if ($provider instanceof AdmitsRowsInterface) {
            return $provider->admitRows($type, $rows, $request);
        }

        $serializer = $server->serializerFor($type);

        return \array_values(\array_filter($rows, static fn(object $row): bool => $provider->fetchOne($type, $serializer->getId($row)) !== null));
    }

    /**
     * Runs `$criteria` (filters, sorting, the page and any count) over already-narrowed
     * members.
     *
     * @param list<object> $members
     *
     * @return CollectionResult<object>
     */
    private function queryMembers(RelationInterface $relation, array $members, CollectionCriteria $criteria): CollectionResult
    {
        if (!$relation->isToMany()) {
            return new CollectionResult($members === [] ? [] : [$members[0]]);
        }

        $type = $relation->relatedTypes()[0] ?? '';

        return (new InMemoryDataProvider($type, $members))->fetchCollection($type, $criteria);
    }
}
