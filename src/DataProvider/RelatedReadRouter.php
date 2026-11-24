<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\LinkRowKeysInterface;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQueryInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decides which provider loads a relation's members for a document (includes,
 * linkage, `?withCount`, the Relationship Queries window), so they are the members
 * the related endpoint serves.
 *
 * The related endpoint reads through the provider of the relation's first related
 * type. A relation is **scoped** when that provider may return fewer members than the
 * parent model holds: it declares so through {@see ScopesRelatedReadsInterface}, or
 * it does not implement that interface. A scoped relation whose related provider is
 * not the parent type's provider is read through the related provider: the whole page
 * at once when its {@see DataProviderInterface::fetchRelatedCollectionBatch()} answers
 * for parents of another type, else per parent, as the endpoint does. Otherwise the
 * parent type's provider batches the whole page.
 *
 * A relation declared with a foreign key is always read through its related type's
 * provider ({@see ReadsByForeignKeyInterface}), for the whole page of parents at once,
 * whatever provider serves them and whatever the parent model holds.
 *
 * A to-one the related provider batches takes its target from the batch on every read
 * path, so the model needs no getter for it. The batch is read once per parent page
 * and request, and {@see forget()} drops it after a write.
 *
 * @internal
 */
final class RelatedReadRouter implements ResetInterface
{
    private readonly IdentifiedTargetGuard $guard;

    /**
     * The to-one batches the related types' own providers answered, per request, keyed
     * by parent type, relation and parent page. Each entry holds its parents, so their
     * object ids stay theirs while the request lives. `false` under a parent type and
     * relation alone records a provider that doesn't batch that relation.
     *
     * @var \WeakMap<JsonApiRequestInterface, array<string, array{parents: list<object>, batch: RelatedBatch}|false>>
     */
    private \WeakMap $relatedBatches;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        ?IdentifiedTargetGuard $guard = null,
    ) {
        $this->guard = $guard ?? new IdentifiedTargetGuard($providers);
        $this->relatedBatches = new \WeakMap();
    }

    /**
     * The relations a pivot row holds, as the providers serving each pivot relation's
     * related type name them.
     */
    public function pivotRows(): PivotRowRelationRouter
    {
        return new PivotRowRelationRouter($this->providers);
    }

    /**
     * Drops every batch read so far, after a write may have changed what it holds.
     */
    public function forget(): void
    {
        $this->relatedBatches = new \WeakMap();
    }

    public function reset(): void
    {
        $this->forget();
    }

    /**
     * Whether `$relation` is a `MorphToMany` declared with `identifiedBy()`.
     *
     * @phpstan-assert-if-true MorphToMany $relation
     */
    public static function identifiesMembers(RelationInterface $relation): bool
    {
        return $relation instanceof MorphToMany && $relation->storesIdentifiers();
    }

    /**
     * Each parent's members for an identified `MorphToMany`, each carrying its link row,
     * read for the whole page at once when the parent type's provider reads a declared
     * relation's rows ({@see ReadsLinkRowsInterface}), in the relation's own order,
     * declared or positioned, else pair order, ties broken by a declared relation's
     * tiebreak. Each guarded type is narrowed to the ids its provider admits across every
     * parent at once ({@see narrowed()}), and the other types keep their pairs unloaded.
     * With `$load`, every type's rows load instead, one
     * {@see FetchesByIdsInterface::fetchByIds()} per type, and a pair whose row doesn't come
     * back is dropped. A type no provider serves keeps its pairs unloaded.
     *
     * @param list<object> $parents
     *
     * @return list<list<IdentifiedMember>>
     *
     * @throws \LogicException when a parent stores a type the relation doesn't declare
     */
    public function identifiedMembers(Server $server, string $type, MorphToMany $relation, array $parents, JsonApiRequestInterface $request, bool $load = false): array
    {
        return \array_map($this->items(...), $this->identifiedPages($server, $type, $relation, $parents, $request, $load));
    }

    /**
     * {@see identifiedMembers()} cut to `$criteria`'s window: each parent's first page of
     * members, in the related endpoint's order, with the page's count or `hasMore`. With
     * no criteria every member is kept. An offset window is cut from the rows read for
     * the whole page at once; any other window pages each parent through
     * {@see pageIdentified()}, as the related endpoint does.
     *
     * @param list<object> $parents
     *
     * @return list<CollectionResult<IdentifiedMember>>
     *
     * @throws \LogicException when a parent stores a type the relation doesn't declare
     */
    public function identifiedPages(Server $server, string $type, MorphToMany $relation, array $parents, JsonApiRequestInterface $request, bool $load = false, ?CollectionCriteria $criteria = null): array
    {
        $window = $criteria?->window;
        if ($window !== null && !$window instanceof OffsetWindow && $criteria !== null) {
            $pages = \array_map(fn(object $parent): CollectionResult => $this->pageIdentified($server, $type, $parent, $relation, $criteria, $request), $parents);

            return $load ? $this->withTargets($server, $pages, $request) : $pages;
        }

        $provider = $this->providers->supportsType($type) ? $this->providers->forType($type) : null;
        $stored = $provider instanceof ReadsLinkRowsInterface && $relation->linkRowFields() !== null
            ? $provider->linkRowMembers($parents, $relation, $request)
            : \array_map(static fn(object $parent): array => $relation->identifiedMembers($parent, $request), $parents);

        $ids = [];
        foreach ($parents as $index => $parent) {
            foreach ($stored[$index] ?? [] as $member) {
                if ($server->hasSerializerFor($member->type) && $this->providers->supportsType($member->type)) {
                    $ids[$member->type][self::wireId($server->serializerFor($member->type), $member->id)] = true;
                }
            }
        }

        $rows = [];
        if ($load) {
            foreach ($ids as $memberType => $set) {
                $rows[$memberType] = $this->fetchByIds($memberType, $server->serializerFor($memberType), \array_map('strval', \array_keys($set)), $request);
            }
        }

        $base = new LinkRowQuery(RelationSorts::ownDefault($relation), window: $window, countable: $criteria !== null && $criteria->wantsCount, fields: $relation->linkRowFields());
        $queries = $load ? \array_fill(0, \count($parents), $base) : $this->narrowed($server, $type, $relation, $parents, $base, $request);

        $pages = [];
        foreach ($parents as $index => $parent) {
            $kept = [];
            foreach ($stored[$index] ?? [] as $member) {
                if (!isset($rows[$member->type])) {
                    $kept[] = $member;

                    continue;
                }

                $row = $rows[$member->type][self::lookupKey($server->serializerFor($member->type), $member->id)] ?? null;
                if ($row !== null) {
                    $kept[] = $member->withTarget($row);
                }
            }
            $pages[] = (new InMemoryLinkRows())->apply($kept, $queries[$index]);
        }

        return $pages;
    }

    /**
     * `$pages` with each member's row loaded, one {@see FetchesByIdsInterface::fetchByIds()}
     * per type across every page. A member whose row doesn't come back is dropped; a type
     * no provider serves keeps its pairs unloaded.
     *
     * @param list<CollectionResult<IdentifiedMember>> $pages
     *
     * @return list<CollectionResult<IdentifiedMember>>
     */
    private function withTargets(Server $server, array $pages, JsonApiRequestInterface $request): array
    {
        $ids = [];
        foreach ($pages as $page) {
            foreach ($this->items($page) as $member) {
                if ($server->hasSerializerFor($member->type) && $this->providers->supportsType($member->type)) {
                    $ids[$member->type][self::wireId($server->serializerFor($member->type), $member->id)] = true;
                }
            }
        }

        $rows = [];
        foreach ($ids as $memberType => $set) {
            $rows[$memberType] = $this->fetchByIds($memberType, $server->serializerFor($memberType), \array_map('strval', \array_keys($set)), $request);
        }

        return \array_map(function (CollectionResult $page) use ($server, $rows): CollectionResult {
            $kept = [];
            foreach ($this->items($page) as $member) {
                if (!isset($rows[$member->type])) {
                    $kept[] = $member;

                    continue;
                }

                $row = $rows[$member->type][self::lookupKey($server->serializerFor($member->type), $member->id)] ?? null;
                if ($row !== null) {
                    $kept[] = $member->withTarget($row);
                }
            }

            return $page instanceof CursorCollectionResult
                ? new CursorCollectionResult($kept, $page->cursorBefore, $page->cursorAfter, $page->hasPrevious, $page->hasMore, $page->total)
                : new CollectionResult($kept, $page->total, $page->windowed, $page->hasMore);
        }, $pages);
    }

    /**
     * `$query` narrowed for each of `$parents`, in their order, through core's
     * {@see LinkRowQuery::narrowed()}: the parents' keys of every guarded member type read
     * at once by the parent type's provider, else in memory, and each guarded type's keys
     * admitted by its provider in one call across them all.
     *
     * @param list<object> $parents
     *
     * @return list<LinkRowQuery>
     */
    public function narrowed(Server $server, string $type, MorphToMany $relation, array $parents, LinkRowQuery $query, JsonApiRequestInterface $request): array
    {
        $provider = $this->providers->supportsType($type) ? $this->providers->forType($type) : null;

        return $query->narrowed(
            $parents,
            $relation,
            $provider instanceof LinkRowKeysInterface ? $provider : new InMemoryLinkRows(),
            $this->guard->guardedMemberTypes($relation),
            fn(string $memberType, array $keys): array => $this->admittedKeys($server, $memberType, $keys, $request),
            $request,
        );
    }

    /**
     * The page of an identified `MorphToMany`: its link rows filtered, sorted and paged
     * per the request's `sort`, `filter` and `$criteria`'s window, over the relation's pivot
     * vocabulary, as {@see IdentifiedMember} values carrying their rows and no target. With
     * no sort requested the relation's default read order applies. Each guarded member type
     * is narrowed first to the storage keys its provider admits ({@see narrowed()}), so the
     * page is cut from the admitted rows and is never short. The parent type's provider queries the rows when it implements
     * {@see LinkRowQueryInterface}; otherwise core's {@see InMemoryLinkRows} does.
     *
     * @return CollectionResult<IdentifiedMember>
     *
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported     when a sort is requested and the relation has no pivot field to sort by
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized  when a requested sort is not one of its pivot sorts
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter is not one of its pivot filters
     */
    public function pageIdentified(Server $server, string $type, object $parent, MorphToMany $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        $query = LinkRowQuery::resolve(
            $relation,
            $criteria->queryParameters->sort,
            $criteria->queryParameters->filter,
            $request,
            $criteria->window,
            $criteria->wantsCount,
        );

        $query = $this->narrowed($server, $type, $relation, [$parent], $query, $request)[0];

        $provider = $this->providers->supportsType($type) ? $this->providers->forType($type) : null;

        return $provider instanceof LinkRowQueryInterface
            ? $provider->queryLinkRows($parent, $relation, $query, $request)
            : (new InMemoryLinkRows())->queryLinkRows($parent, $relation, $query, $request);
    }

    /**
     * The storage keys among `$keys` whose rows the provider of `$type` admits, matched
     * through their wire ids.
     *
     * @param array<string|int> $keys
     *
     * @return list<string|int>
     */
    private function admittedKeys(Server $server, string $type, array $keys, JsonApiRequestInterface $request): array
    {
        if ($keys === [] || !$server->hasSerializerFor($type) || !$this->providers->supportsType($type)) {
            return [];
        }

        $serializer = $server->serializerFor($type);
        $byWireId = [];
        $wireIds = [];
        foreach ($keys as $key) {
            $wireId = self::wireId($serializer, $key);
            $byWireId[StoredIdForms::comparable($wireId)][] = $key;
            $wireIds[] = $wireId;
        }

        $admitted = [];
        foreach ($this->admitIds($type, $serializer, self::inEitherCase($wireIds), $request) as $id) {
            // A uuid-like id admitted in either case releases its keys once.
            \array_push($admitted, ...$byWireId[StoredIdForms::comparable($id)] ?? []);
            unset($byWireId[StoredIdForms::comparable($id)]);
        }

        return $admitted;
    }

    /**
     * The related page of a `MorphToMany` declared with `identifiedBy()`: the
     * {@see pageIdentified()} page with only its rows loaded, one
     * {@see FetchesByIdsInterface::fetchByIds()} per type. `$types` receives the type each
     * returned row renders as, keyed by object id, since one class may serve several, and
     * `$pivots` the `meta.pivot` each renders, when the relation declares pivot fields.
     *
     * @param array<int, string>               $types
     * @param array<int, array<string, mixed>> $pivots
     *
     * @return CollectionResult<object>
     */
    public function fetchIdentified(Server $server, string $type, object $parent, MorphToMany $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request, array &$types = [], array &$pivots = []): CollectionResult
    {
        $page = $this->pageIdentified($server, $type, $parent, $relation, $criteria, $request);

        $members = [];
        foreach ($page->items as $member) {
            if ($member instanceof IdentifiedMember) {
                $members[] = $member;
            }
        }

        $loaded = $this->loadMembers($server, $members, $request, $types, $byObject);
        if ($relation->pivotFields() !== []) {
            foreach ($byObject as $objectId => $member) {
                $pivots[$objectId] = $relation->pivotValues($member, $parent, $request, $server->outputTimezone());
            }
        }

        return new CollectionResult($loaded, $page->total, $page->windowed, $page->hasMore);
    }

    /**
     * @param CollectionResult<IdentifiedMember> $result
     *
     * @return list<IdentifiedMember>
     */
    private function items(CollectionResult $result): array
    {
        $items = [];
        foreach ($result->items as $item) {
            if ($item instanceof IdentifiedMember) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The rows of `$members`, in their order, one {@see FetchesByIdsInterface::fetchByIds()}
     * per type, leaving out a member whose row doesn't come back.
     *
     * @param list<IdentifiedMember>               $members
     * @param array<int, string>                   $types    receives each row's type, keyed by object id
     * @param array<int, IdentifiedMember>|null    $byObject receives each row's member, keyed by object id
     *
     * @param-out array<int, IdentifiedMember> $byObject
     *
     * @return list<object>
     */
    private function loadMembers(Server $server, array $members, JsonApiRequestInterface $request, array &$types, ?array &$byObject = null): array
    {
        $byObject = [];
        $ids = [];
        foreach ($members as $member) {
            if ($server->hasSerializerFor($member->type) && $this->providers->supportsType($member->type)) {
                $ids[$member->type][self::wireId($server->serializerFor($member->type), $member->id)] = true;
            }
        }

        $rows = [];
        foreach ($ids as $type => $set) {
            $rows[$type] = $this->fetchByIds($type, $server->serializerFor($type), \array_map('strval', \array_keys($set)), $request);
        }

        $loaded = [];
        foreach ($members as $member) {
            if (!isset($rows[$member->type])) {
                continue;
            }

            $row = $rows[$member->type][self::lookupKey($server->serializerFor($member->type), $member->id)] ?? null;
            if ($row !== null) {
                $loaded[] = $row;
                $types[\spl_object_id($row)] = $member->type;
                $byObject[\spl_object_id($row)] = $member;
            }
        }

        return $loaded;
    }

    /**
     * The ids among `$ids` whose rows the provider of `$type` admits: one id-only probe
     * when it can, else one load by id, else one `fetchOne()` per id.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function admitIds(string $type, SerializerInterface $serializer, array $ids, JsonApiRequestInterface $request): array
    {
        $provider = $this->providers->forType($type);
        if ($provider instanceof AdmitsIdsInterface) {
            return $provider->admitIds($type, $ids, $request);
        }

        return \array_map('strval', \array_keys($this->fetchByIds($type, $serializer, $ids, $request)));
    }

    /**
     * Whether the provider of any type `$relation` reaches scopes its reads, so a
     * polymorphic relation is scoped when any of its types is. A related type with no
     * provider has nothing to scope by.
     */
    public function isScoped(RelationInterface $relation): bool
    {
        if ($this->readsByForeignKey($relation)) {
            return true;
        }

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
     * Whether a provider serves `$type`.
     */
    public function servesType(string $type): bool
    {
        return $this->providers->supportsType($type);
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
     * Whether `$relation`'s members must be read through the related endpoint's
     * provider rather than batched by the parent type's provider.
     */
    public function readsThroughRelatedProvider(string $type, RelationInterface $relation): bool
    {
        if ($this->readsByForeignKey($relation)) {
            return true;
        }

        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$this->providers->supportsType($relatedType) || !$this->isScoped($relation)) {
            return false;
        }

        return \count($relation->relatedTypes()) > 1
            || !$this->providers->supportsType($type)
            || $this->providers->forType($type) !== $this->providers->forType($relatedType);
    }

    /**
     * Whether `$relation` is a to-many read off its parent model because its related
     * type's provider can't scope rows to parents of `$type`
     * ({@see ScopesToParentsInterface}) and nothing else reaches them: no foreign key,
     * no pivot and no scope on the related type. Its pages are cut in memory from what
     * the model holds, as an `extractUsing()` relation's are.
     */
    public function readsOffModel(string $type, RelationInterface $relation): bool
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$relation->isToMany() || \count($relation->relatedTypes()) !== 1
            || ForeignKey::nameOf($relation) !== null || !$this->providers->supportsType($relatedType)
            || $this->isPivot($relation) || $this->isScoped($relation)) {
            return false;
        }

        $provider = $this->providers->forType($relatedType);

        return $provider instanceof ScopesToParentsInterface && !$provider->scopesToParentsOf($type);
    }

    /**
     * Whether `$relation` declares a foreign key and a provider serves its related type,
     * so every read of it goes through {@see foreignKeyBatch()}.
     */
    public function readsByForeignKey(RelationInterface $relation): bool
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;

        return ForeignKey::nameOf($relation) !== null
            && $relatedType !== null
            && \count($relation->relatedTypes()) === 1
            && $this->providers->supportsType($relatedType);
    }

    /**
     * Each parent's page of `$relation` read through its foreign key by the related
     * type's provider, keyed by parent wire id: the rows whose foreign key holds the
     * parent's key, under the provider's scope, per `$criteria`.
     *
     * @param list<object> $parents
     *
     * @throws \LogicException when the foreign key doesn't resolve or the related type's
     *                         provider can't read through one; warm-up refuses both
     */
    public function foreignKeyBatch(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        [$relatedType, $provider, $foreignKey] = $this->foreignKeyReader($server, $type, $relation);
        $keyed = $this->foreignKeyParents($server, $type, $parents);
        if ($keyed->isEmpty()) {
            return new RelatedBatch([]);
        }

        return $provider->fetchByForeignKey($relatedType, $relation, $foreignKey, $keyed, $criteria, $request);
    }

    /**
     * The members `$relation` reads through its foreign key for `$parent`, unpaged and
     * unfiltered, under the related type's scope, as `[type, wire id]` pairs: the members a
     * replacement of a membership-locked to-many is compared with.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function foreignKeyMembers(Server $server, string $type, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $serializer = $server->serializerFor($relatedType);
        $criteria = new CollectionCriteria(new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: []), request: $request);
        $members = [];
        foreach ($this->foreignKeyPage($server, $type, $parent, $relation, $criteria, $request)->items as $row) {
            if (\is_object($row)) {
                $members[] = [$relatedType, $serializer->getId($row)];
            }
        }

        return $members;
    }

    /**
     * `$parent`'s page of `$relation` read through its foreign key, as its related and
     * relationship endpoints serve it.
     *
     * @return CollectionResult<object>
     */
    public function foreignKeyPage(Server $server, string $type, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->foreignKeyBatch($server, $type, [$parent], $relation, $criteria, $request)
            ->for($server->serializerFor($type)->getId($parent));
    }

    /**
     * The parents keyed by wire id, each with the key its related rows' foreign key
     * holds. A parent with no id is left out.
     *
     * @param list<object> $parents
     */
    public function foreignKeyParents(Server $server, string $type, array $parents): ForeignKeyParents
    {
        $serializer = $server->serializerFor($type);
        $objects = [];
        $keys = [];
        foreach ($parents as $parent) {
            $wireId = $serializer->getId($parent);
            if ($wireId === '') {
                continue;
            }
            $objects[$wireId] = $parent;
            $keys[$wireId] = ForeignKey::parentKey($serializer, $parent);
        }

        return new ForeignKeyParents($type, $objects, $keys, self::encoderOf($serializer));
    }

    /**
     * The related type, its provider and the resolved foreign key `$relation` reads
     * through.
     *
     * @return array{0: string, 1: ReadsByForeignKeyInterface, 2: ForeignKey}
     *
     * @throws \LogicException when the foreign key doesn't resolve or the provider can't read through one
     */
    private function foreignKeyReader(Server $server, string $type, RelationInterface $relation): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $foreignKey = ForeignKey::of($relation, $server);
        if ($foreignKey === null) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" reads through the foreign key "%s", which is no stored field or to-one relation of the resource serving type "%s".',
                $relation->name(),
                $type,
                (string) ForeignKey::nameOf($relation),
                $relatedType,
            ));
        }

        $provider = $this->providers->forType($relatedType);
        if (!$provider instanceof ReadsByForeignKeyInterface) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" reads through the foreign key "%s", but the %s serving type "%s" can\'t read through one. Implement %s on it.',
                $relation->name(),
                $type,
                $foreignKey->name,
                $provider::class,
                $relatedType,
                ReadsByForeignKeyInterface::class,
            ));
        }

        return [$relatedType, $provider, $foreignKey];
    }

    /**
     * Loads `$relation` for every parent per `$criteria`, keyed by parent wire id.
     *
     * @param list<object> $parents
     */
    public function batch(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        if ($this->readsByForeignKey($relation)) {
            return $this->foreignKeyBatch($server, $type, $parents, $relation, $criteria, $request);
        }

        if ($this->isComputed($relation)) {
            $serializer = $server->serializerFor($type);
            $results = [];
            foreach ($this->admitComputed($server, $parents, $relation, $request) as $index => $members) {
                $results[$serializer->getId($parents[$index])] = $this->queryMembers($relation, $members, $criteria);
            }

            return new RelatedBatch($results);
        }

        if (!$this->readsThroughRelatedProvider($type, $relation)) {
            $relatedType = $relation->relatedTypes()[0] ?? $type;

            $batch = $this->providers->forType($this->providers->supportsType($type) ? $type : $relatedType)
                ->fetchRelatedCollectionBatch($type, $parents, $relation, $criteria, $request);

            // Members the related provider can't scope to these parents are the model's own.
            return !$batch->isSupported() && $this->readsOffModel($type, $relation) ? RelatedBatch::computed() : $batch;
        }

        $batch = $this->relatedProviderBatch($type, $parents, $relation, $criteria, $request, $server);
        if ($batch !== null) {
            return $batch;
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
     * The page of `$relation` the related type's own provider answers for `$parents` in
     * one {@see DataProviderInterface::fetchRelatedCollectionBatch()} call, or `null` when
     * the relation is not read through that provider, or the provider doesn't batch it.
     *
     * Only a monomorphic relation is asked, and a pivot `belongsToMany` never is: the
     * related endpoint reads it through its pivot query, per parent. A provider that
     * answers {@see RelatedBatch::unsupported()} is not asked again for the relation in
     * the same request. One that answers {@see RelatedBatch::computed()} is treated the
     * same way: the model holds the value, so it is read off each parent and checked
     * against the related provider's scope, which the related type still applies.
     *
     * A to-one is asked with no criteria, since its batch is the source of each parent's
     * target: the first item, or none. That batch is kept for the request, so every path
     * that reads the same parents' target shares one call.
     *
     * @param list<object> $parents
     */
    public function relatedProviderBatch(string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request, ?Server $server = null): ?RelatedBatch
    {
        if (\count($relation->relatedTypes()) !== 1 || $this->isPivot($relation) || !$this->readsThroughRelatedProvider($type, $relation)) {
            return null;
        }

        $relationKey = $type . "\0" . $relation->name();
        $known = $this->relatedBatches[$request] ?? [];
        if (($known[$relationKey] ?? null) === false) {
            return null;
        }

        $pageKey = null;
        if (!$relation->isToMany()) {
            $pageKey = $relationKey . "\0" . \implode(',', \array_map(\spl_object_id(...), $parents));
            $entry = $known[$pageKey] ?? null;
            if (\is_array($entry)) {
                return $entry['batch'];
            }
            $criteria = self::plainCriteria($request);
        }

        $relatedType = $relation->relatedTypes()[0];
        $batch = $this->readsByForeignKey($relation) && $server !== null
            ? $this->foreignKeyBatch($server, $type, $parents, $relation, $criteria, $request)
            : $this->providers->forType($relatedType)->fetchRelatedCollectionBatch($type, $parents, $relation, $criteria, $request);

        if (!$batch->isSupported()) {
            $known[$relationKey] = false;
            $this->relatedBatches[$request] = $known;

            return null;
        }

        if ($pageKey !== null) {
            $known[$pageKey] = ['parents' => $parents, 'batch' => $batch];
            $this->relatedBatches[$request] = $known;
        }

        return $batch;
    }

    /**
     * Each parent's to-one target, keyed by parent wire id, when the related type's own
     * provider batches `$relation` ({@see relatedProviderBatch()}), or `null` when it
     * doesn't and the target is read off the model.
     *
     * @param list<object> $parents
     *
     * @return array<string, ?object>|null
     */
    public function relatedToOneTargets(Server $server, string $type, array $parents, RelationInterface $relation, JsonApiRequestInterface $request): ?array
    {
        if ($relation->isToMany()) {
            return null;
        }

        $batch = $this->relatedProviderBatch($type, $parents, $relation, self::plainCriteria($request), $request, $server);
        if ($batch === null) {
            return null;
        }

        $serializer = $server->serializerFor($type);
        $targets = [];
        foreach ($parents as $parent) {
            $wireId = $serializer->getId($parent);
            $targets[$wireId] = null;
            foreach ($batch->for($wireId)->items as $item) {
                if (\is_object($item)) {
                    $targets[$wireId] = $item;
                }
                break;
            }
        }

        return $targets;
    }

    /**
     * No filters, sorts or window: each parent's whole related set, as its provider
     * scopes it.
     */
    private static function plainCriteria(JsonApiRequestInterface $request): CollectionCriteria
    {
        return new CollectionCriteria(new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: $request->getPagination()));
    }

    /**
     * Each parent's target for a `MorphTo` declared with `identifiedBy()`, loaded from
     * the stored pairs with one {@see FetchesByIdsInterface::fetchByIds()} per type, limited to
     * `$types` when given. An entry is `null` for an empty
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
                ? ['type' => $type, 'target' => $rows[$type][self::lookupKey($server->serializerFor($type), $id)] ?? null, 'loaded' => true]
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
        $ids = self::inEitherCase($ids);
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
            // A uuid-like id a text column keeps in another case still names the row.
            $byId[StoredIdForms::comparable($serializer->getId($row))] = $row;
        }

        return $byId;
    }

    /**
     * The key a row loaded for a stored id is found under: its wire id, with a uuid-like
     * id in lower case, since a text column may keep it in either case.
     */
    private static function lookupKey(SerializerInterface $serializer, string|int $id): string
    {
        return StoredIdForms::comparable(self::wireId($serializer, $id));
    }

    /**
     * `$ids` with a uuid-like id listed in both cases, so a provider finds a row whatever
     * case its own id column keeps.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function inEitherCase(array $ids): array
    {
        $forms = [];
        foreach (StoredIdForms::forms(null, $ids) as $id) {
            if (\is_string($id)) {
                $forms[$id] = $id;
            }
        }

        return \array_values($forms);
    }

    /**
     * The id encoder `$serializer` declares on its id field, if any.
     */
    private static function encoderOf(SerializerInterface $serializer): ?IdEncoderInterface
    {
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    return $field->encoder();
                }
            }
        }

        return null;
    }

    /**
     * A stored id, the storage key or the wire id, as `$serializer` renders it: through
     * its id field's encoder when it declares one, as core renders the pair's linkage.
     */
    public static function wireId(SerializerInterface $serializer, string|int $id): string
    {
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    return StoredIdForms::wireId($field->encoder(), $id);
                }
            }
        }

        return (string) $id;
    }

    /**
     * The `?withCount` total of `$relation` per parent wire id, over the members the
     * related endpoint serves. Read through a related provider that batches the
     * relation, each answered parent's count is its result's total, else its item count;
     * a parent the batch leaves out is counted on its own.
     *
     * @param list<object> $parents
     *
     * @return array<int|string, int>
     */
    public function counts(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        // Counted by the related type's provider over the rows whose foreign key holds
        // each parent's key, every parent present.
        if ($this->readsByForeignKey($relation)) {
            [$relatedType, $provider, $foreignKey] = $this->foreignKeyReader($server, $type, $relation);
            $keyed = $this->foreignKeyParents($server, $type, $parents);
            if ($keyed->isEmpty()) {
                return [];
            }

            return $provider->countByForeignKey($relatedType, $relation, $foreignKey, $keyed, $criteria, $request) + \array_fill_keys(\array_map('strval', \array_keys($keyed->keys)), 0);
        }

        // The pairs each guarded type's provider admits, before any page.
        if (self::identifiesMembers($relation)) {
            $serializer = $server->serializerFor($type);
            $counts = [];
            foreach ($this->identifiedMembers($server, $type, $relation, $parents, $request) as $index => $members) {
                $counts[$serializer->getId($parents[$index])] = \count($members);
            }

            return $counts;
        }

        if ($this->isComputed($relation)) {
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
            $counts = $this->providers->forType($type)->countRelated($type, $parents, $relation, $criteria, $request);
            if ($counts !== [] || !$this->readsOffModel($type, $relation)) {
                return $counts;
            }

            // Counted over what each parent's model holds, as its pages are cut.
            $serializer = $server->serializerFor($type);
            foreach ($this->memberPages($server, $parents, $relation, $criteria, $request) as $index => $page) {
                $counts[$serializer->getId($parents[$index])] = \is_array($page->items) ? \count($page->items) : \iterator_count($page->items);
            }

            return $counts;
        }

        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $provider = $this->providers->forType($relatedType);
        $serializer = $server->serializerFor($type);

        // The pivot query when the related endpoint would run it, so the count is of the
        // same rows.
        $pivot = $provider instanceof PivotAwareProviderInterface && $provider->supportsPivot($relatedType, $relation);

        // A batching related provider counts the page in one call, over the same filtered
        // set with no window.
        $batch = $pivot || !$relation->isToMany() ? null : $this->relatedProviderBatch($type, $parents, $relation, $criteria, $request, $server);

        $counts = [];
        foreach ($parents as $parent) {
            $wireId = $serializer->getId($parent);
            if ($batch !== null && $batch->has($wireId)) {
                $answered = $batch->for($wireId);
                $counts[$wireId] = $answered->total
                    ?? (\is_array($answered->items) ? \count($answered->items) : \iterator_count($answered->items));

                continue;
            }

            $result = $pivot
                ? $provider->fetchRelatedPivotCollection($relatedType, $parent, $relation, $criteria, $request)
                : $provider->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
            $counts[$wireId] = \is_array($result->items) ? \count($result->items) : \iterator_count($result->items);
        }

        return $counts;
    }

    /**
     * Whether each parent's to-one target survives `$criteria` (and the related
     * provider's scope), keyed by parent wire id. Read through the related provider,
     * the target comes from its batch when it batches the relation
     * ({@see relatedToOneTargets()}), else off the model.
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

        // A batching related provider is the source of each target; otherwise the model is.
        $targets = $this->relatedToOneTargets($server, $type, $parents, $relation, $request);

        $matches = [];
        foreach ($parents as $parent) {
            $wireId = $serializer->getId($parent);
            $target = $targets !== null ? ($targets[$wireId] ?? null) : $relation->readValue($parent, $request);
            $matches[$wireId] = \is_object($target)
                && $provider->relatedToOneMatches($relatedType, $target, $relation, $criteria, $request);
        }

        return $matches;
    }

    /**
     * Whether `$relation`'s value comes from an `extractUsing()` closure. Core keeps the
     * closure on the field's protected state, so this reads it by reflection. A pivot
     * `belongsToMany` is not counted: its closure only feeds core's model read, and the
     * provider's pivot query is what its related endpoint serves. Nor is a relation
     * declared with a foreign key, which decides its reads.
     *
     * A computed relation's endpoints, counts and windows always read the closure's
     * value, narrowed to the rows any scoped type admits, since its storage need not be
     * anything a provider can query.
     */
    public function isComputed(RelationInterface $relation): bool
    {
        if (!$relation instanceof AbstractFieldValue || $this->isPivot($relation) || $this->readsByForeignKey($relation)) {
            return false;
        }

        return self::extractsValue($relation);
    }

    /**
     * Whether `$relation` declares an `extractUsing()` closure. Core keeps the closure on
     * the field's protected state, so this reads it by reflection.
     */
    public static function extractsValue(RelationInterface $relation): bool
    {
        if (!$relation instanceof AbstractFieldValue) {
            return false;
        }

        static $state = null;
        $state ??= new \ReflectionProperty(AbstractFieldValue::class, 'state');
        $fieldState = $state->getValue($relation);

        return \is_object($fieldState) && \property_exists($fieldState, 'extractUsing') && $fieldState->extractUsing !== null;
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
        if ($this->isComputed($relation)) {
            return $this->fetchComputed($server, $parent, $relation, $criteria, $request);
        }

        $relatedType = $relation->relatedTypes()[0] ?? '';
        $unpaged = new CollectionCriteria($criteria->queryParameters, $criteria->filters, $criteria->sorts, null, $criteria->defaultSort, $criteria->aliasOf, request: $criteria->request, paths: $criteria->paths);
        $result = $this->providers->forType($relatedType)->fetchRelatedCollection($relatedType, $parent, $relation, $unpaged, $request);

        return $this->queryMembers($relation, $this->narrow($server, [$this->objects($result->items)], $relation, $request)[0], $criteria);
    }

    /**
     * The rows of `$rows`, reached through `$relation`, that each scoped related type's
     * provider admits for the caller, in their given order: the narrowing a filter's walk
     * through `$relation` applies in memory.
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    public function admitReached(Server $server, array $rows, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        return $this->narrow($server, [$rows], $relation, $request)[0];
    }

    /**
     * The narrowing {@see admitReached()} applies, as a closure for {@see FilterPaths::of()},
     * or null when `$resources` is not a server.
     *
     * @return (\Closure(RelationInterface, list<object>, JsonApiRequestInterface): list<object>)|null
     */
    public function admissionFor(SerializerResolverInterface $resources): ?\Closure
    {
        return $resources instanceof Server
            ? fn(RelationInterface $relation, array $rows, JsonApiRequestInterface $request): array => $this->admitReached($resources, \array_values($rows), $relation, $request)
            : null;
    }

    /**
     * The rows a relation declared with a foreign key holds for one parent, read through
     * the related type's provider under its scope, as a closure for {@see FilterPaths::of()}
     * an in-memory filter walks such a relation with, since the parent model holds none.
     * The closure answers `null` for any other relation. Null when `$resources` is not a
     * server.
     *
     * @return (\Closure(RelationInterface, string, object, JsonApiRequestInterface): (list<object>|null))|null
     */
    public function foreignKeyReaderFor(SerializerResolverInterface $resources): ?\Closure
    {
        if (!$resources instanceof Server) {
            return null;
        }

        return function (RelationInterface $relation, string $parentType, object $row, JsonApiRequestInterface $request) use ($resources): ?array {
            if (!$this->readsByForeignKey($relation) || !$resources->hasSerializerFor($parentType)) {
                return null;
            }

            $criteria = new CollectionCriteria(new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: []), request: $request);

            $rows = [];
            foreach ($this->foreignKeyPage($resources, $parentType, $row, $relation, $criteria, $request)->items as $item) {
                $rows[] = $item;
            }

            return $rows;
        };
    }

    /**
     * The narrowing a `WhereMorph` applies in memory, as a closure for
     * {@see FilterPaths::of()}: of the storage keys of `$type` a polymorphic relation
     * stores, those the type's provider admits for the caller. Null when `$resources` is
     * not a server.
     *
     * @return (\Closure(RelationInterface, string, list<mixed>, JsonApiRequestInterface): list<mixed>)|null
     */
    public function morphAdmissionFor(SerializerResolverInterface $resources): ?\Closure
    {
        if (!$resources instanceof Server) {
            return null;
        }

        return fn(RelationInterface $relation, string $type, array $keys, JsonApiRequestInterface $request): array => $this->admitMorphKeys($resources, $relation, $type, $keys, $request);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return list<mixed>
     */
    private function admitMorphKeys(Server $server, RelationInterface $relation, string $type, array $keys, JsonApiRequestInterface $request): array
    {
        if (!$this->isScopedType($type, $relation)) {
            return \array_values($keys);
        }

        return $this->admittedKeys($server, $type, \array_values(\array_filter($keys, static fn(mixed $key): bool => \is_string($key) || \is_int($key))), $request);
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
     * A to-many's members as each of `$parents` holds them, narrowed per member type to
     * the rows each scoped type's provider admits, then run through `$criteria`: the page
     * a polymorphic relation's related endpoint serves, or a relation whose value the
     * model computes, for every parent.
     *
     * @param list<object> $parents
     *
     * @return list<CollectionResult<object>>
     */
    public function memberPages(Server $server, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        $candidates = [];
        foreach ($parents as $parent) {
            $value = $relation->readValue($parent, $request);
            $candidates[] = \is_iterable($value) ? $this->objects($value) : [];
        }

        $members = $this->isScoped($relation) ? $this->narrow($server, $candidates, $relation, $request) : $candidates;

        return \array_map(fn(array $narrowed): CollectionResult => $this->queryMembers($relation, $narrowed, $criteria), $members);
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
