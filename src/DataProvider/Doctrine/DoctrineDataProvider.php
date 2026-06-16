<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\CriteriaApplier;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\PivotAwareProviderInterface;
use haddowg\JsonApiBundle\DataProvider\PivotCollectionResult;
use haddowg\JsonApiBundle\DataProvider\PivotFields;
use haddowg\JsonApiBundle\DataProvider\PreloadsIncludesInterface;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;

/**
 * The reference Doctrine ORM read provider, wired only when `doctrine/orm` is
 * installed **and** at least one resource maps an entity (the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * removes the service otherwise), because Doctrine is a `require-dev` +
 * `suggest` dependency, not a hard one.
 *
 * A collection fetch is one `QueryBuilder` pipeline: every supporting
 * {@see DoctrineExtensionInterface} customizes the builder first (base
 * constraints, query shaping), then the shared {@see CriteriaApplier} matches
 * the requested `filter[…]`/`sort` parameters against the declared
 * vocabularies and pushes each down through the
 * {@see DoctrineFilterHandler}/{@see DoctrineSortHandler}; a windowed fetch
 * then runs a `COUNT` over the filtered (un-ordered, un-windowed) query before
 * applying the window as `LIMIT`/`OFFSET` — items are never over-fetched.
 * Single fetches run through the same extension pipeline (so a scope holds for
 * `GET /{type}/{id}` too), falling back to `find()` — and its identity-map
 * fast path — only when no extension supports the type.
 *
 * The `type → entity-class` map is populated by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * from each resource's `#[AsJsonApiResource(entity: …)]` declaration.
 *
 * One instance serves every entity-mapped type — a different entity class per
 * type — so `TEntity` cannot narrow past `object`.
 *
 * When a type's resource attaches an id encoder ({@see \haddowg\JsonApi\Resource\Field\Id::encodeUsing()})
 * the JSON:API `id` is the wire form of a distinct storage key, so this provider
 * **decodes** the route `{id}` to its storage key (via the injected
 * {@see IdEncoderResolver}) before every lookup — the wire-id SPI never changes,
 * only the Doctrine impl decodes (ADR 0038). An undecodable id short-circuits to a
 * `404` (no query runs). A type with no encoder decodes to itself, so the path is
 * identical to today.
 *
 * It also implements {@see PreloadsIncludesInterface}: when the optional
 * `shipmonk/doctrine-entity-preloader` library is installed (and so an
 * {@see IncludePreloader} is injected), the handler asks it to batch eager-load a
 * read's effective `?include` tree before rendering, so includes do not N+1
 * (ADR 0035). Without the library the injected preloader is null and the capability
 * is a no-op — the includes render lazily.
 *
 * @implements DataProviderInterface<object>
 * @implements PivotAwareProviderInterface<object>
 */
final class DoctrineDataProvider implements DataProviderInterface, PreloadsIncludesInterface, PivotAwareProviderInterface
{
    /**
     * The root alias every generated QueryBuilder uses; handlers re-read it
     * from the builder, so this is a naming choice, not a contract.
     */
    private const string ROOT_ALIAS = 'resource';

    private readonly CriteriaApplier $applier;

    private readonly DoctrineFilterHandler $filterHandler;

    private readonly DoctrineSortHandler $sortHandler;

    /**
     * @var list<DoctrineExtensionInterface>
     */
    private readonly array $extensions;

    /**
     * @param array<string, class-string>          $entityClassByType a `type → entity FQCN` map
     * @param IdEncoderResolver                    $idEncoders        resolves a type's id encoder (route `{id}` decode)
     * @param iterable<DoctrineExtensionInterface> $extensions        in descending tag-priority order
     * @param ?IncludePreloader                    $preloader         the optional include batch-preloader (null when `shipmonk/doctrine-entity-preloader` is absent)
     * @param ?PivotAssociationResolver            $pivotAssociations resolves a `belongsToMany` pivot relation's association entity (always wired under Doctrine)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
        private readonly IdEncoderResolver $idEncoders,
        iterable $extensions = [],
        // Not readonly: the include preloader is a pure optimization that can be
        // toggled off at runtime (the conformance suite disables it to prove the
        // rendered document is identical with and without preloading).
        private ?IncludePreloader $preloader = null,
        private readonly ?PivotAssociationResolver $pivotAssociations = null,
    ) {
        $this->extensions = \is_array($extensions) ? \array_values($extensions) : \iterator_to_array($extensions, false);
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new DoctrineFilterHandler();
        $this->sortHandler = new DoctrineSortHandler();
    }

    public function supports(string $type): bool
    {
        return isset($this->entityClassByType[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $entityClass = $this->entityClassFor($type);

        // Decode the wire id to its storage key when the type declares an encoder.
        // An undecodable id is a 404: no entity holds that key, so short-circuit
        // without querying (the SPI stays wire-id — only this impl decodes; ADR 0038).
        $encoder = $this->idEncoders->encoderFor($type);
        if ($encoder !== null) {
            $storageKey = $encoder->decode($id);
            if ($storageKey === null) {
                return null;
            }
        } else {
            $storageKey = $id;
        }

        $extensions = $this->extensionsFor($type);
        if ($extensions === []) {
            return $this->entityManager->find($entityClass, $storageKey);
        }

        $builder = $this->entityManager
            ->getRepository($entityClass)
            ->createQueryBuilder(self::ROOT_ALIAS);

        // String-id lookups imply a single-field identifier (composite ids
        // cannot round-trip through one JSON:API id segment anyway).
        $idField = $this->entityManager->getClassMetadata($entityClass)->getSingleIdentifierFieldName();
        $builder
            ->andWhere(\sprintf('%s.%s = :jsonapi_id', self::ROOT_ALIAS, $idField))
            ->setParameter('jsonapi_id', $storageKey);

        foreach ($extensions as $extension) {
            $builder = $extension->apply($builder, $type, QueryPurpose::FetchOne);
        }

        $result = $builder->getQuery()->getOneOrNullResult();

        return \is_object($result) ? $result : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $builder = $this->entityManager
            ->getRepository($this->entityClassFor($type))
            ->createQueryBuilder(self::ROOT_ALIAS);

        foreach ($this->extensionsFor($type) as $extension) {
            $builder = $extension->apply($builder, $type, QueryPurpose::FetchCollection);
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        $window = $criteria->window;
        if ($window === null) {
            return new CollectionResult($this->items($builder));
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        $total = $this->count($builder);
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit);

        return new CollectionResult($this->items($builder), $total);
    }

    /**
     * Scopes a related to-many to its parent. A single-valued inverse
     * association (the OneToMany case, whose related entity carries the owning
     * foreign key) is scoped by that FK as a fast-path; any other to-many
     * (owning-side, or many-to-many on either side) is scoped by an `IN`
     * subquery rooted on the parent — the related entity stays the OUTER query
     * root, so the shared filter/sort/count/window machinery applies
     * identically to both branches. `$request` is unused (Doctrine push-down).
     *
     * A polymorphic to-many ({@see \haddowg\JsonApi\Resource\Field\MorphToMany},
     * `relatedTypes()` of more than one type) is a deliberate boundary — like the
     * many-to-many subquery scope above, this provider executes one scoped query
     * against a single related entity class, and a polymorphic collection's members
     * span entity classes, so there is no single query to run. It throws; a host
     * that needs it supplies a custom {@see DataProviderInterface} that resolves the
     * related members across types (ADR 0032).
     */
    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        if (\count($relation->relatedTypes()) > 1) {
            throw new \LogicException(\sprintf(
                'The %s does not support a polymorphic (morph-to-many) related collection for relationship "%s": its members span entity classes and cannot be one scoped query. Supply a custom DataProvider that resolves the related members across types.',
                self::class,
                $relation->name(),
            ));
        }

        $property = $relation->column() ?? $relation->name();
        $relatedClass = $this->entityClassFor($relatedType);

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS);

        $owningField = $this->inverseOwningField($parent, $property);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);

        // Fast-path: a single-valued inverse association (the related entity
        // carries the owning FK). A many-to-many *inverse* side also has a
        // non-null mappedBy, but it points to a COLLECTION — the
        // isSingleValuedAssociation guard routes it to the subquery instead.
        if ($owningField !== null && $relatedMetadata->isSingleValuedAssociation($owningField)) {
            $builder
                ->andWhere(\sprintf('%s.%s = :jsonapi_parent', self::ROOT_ALIAS, $owningField))
                ->setParameter('jsonapi_parent', $parent);
        } else {
            // Subquery branch (owning-side, or many-to-many either side): scope
            // by membership with an IN subquery that keeps the related entity as
            // the outer query root. getClassMetadata resolves a proxy class to
            // its real entity name.
            $parentClass = $this->entityManager->getClassMetadata($parent::class)->getName();
            $relatedIdField = $relatedMetadata->getSingleIdentifierFieldName();

            $sub = $this->entityManager->createQueryBuilder()
                ->select(\sprintf('related_scope.%s', $relatedIdField))
                ->from($parentClass, 'parent_scope')
                ->innerJoin(\sprintf('parent_scope.%s', $property), 'related_scope')
                ->where('parent_scope = :jsonapi_parent');

            $builder
                ->andWhere($builder->expr()->in(
                    \sprintf('%s.%s', self::ROOT_ALIAS, $relatedIdField),
                    $sub->getDQL(),
                ))
                ->setParameter('jsonapi_parent', $parent); // bind on the OUTER builder, which executes
        }

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, $relatedType, QueryPurpose::FetchCollection);
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        $window = $criteria->window;
        if ($window === null) {
            return new CollectionResult($this->items($builder));
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        // A non-countable related to-many paginates count-FREE: no COUNT query runs;
        // instead the page is over-fetched by one (limit+1) and the surplus row
        // signals a further page, driving the count-free page's `next` link without
        // a total (bundle ADR 0052). A countable relation counts the pre-window total
        // as before, emitting total + `last`.
        if (!$relation->isCountable()) {
            return $this->countFreePage($builder, $window);
        }

        $total = $this->count($builder);
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit);

        return new CollectionResult($this->items($builder), $total);
    }

    /**
     * The count-free page for a non-countable related collection: the window is
     * applied with `LIMIT $limit + 1` (one row past the page), so the surplus row
     * proves a further page exists without any `COUNT`. The surplus is dropped from
     * the rendered items and reported through {@see CollectionResult::$hasMore}; the
     * result carries a `null` total with {@see CollectionResult::$windowed} true so
     * the handler builds a count-free page (bundle ADR 0052 / core ADR 0057).
     *
     * @return CollectionResult<object>
     */
    private function countFreePage(QueryBuilder $builder, OffsetWindow $window): CollectionResult
    {
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit + 1);
        $rows = $this->items($builder);

        $hasMore = \count($rows) > $window->limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $window->limit);
        }

        return new CollectionResult($rows, total: null, windowed: true, hasMore: $hasMore);
    }

    /**
     * The batched, pushed-down cardinality of `$relation` for a page of parents
     * (bundle ADR 0052). ONE grouped query rooted on the PARENT entity joins the
     * related collection and counts it per parent —
     *
     *     SELECT parent.<id>, COUNT(related.<relatedId>)
     *     FROM <ParentEntity> parent
     *     LEFT JOIN parent.<property> related
     *     WHERE parent.<id> IN (:pageIds)
     *     GROUP BY parent.<id>
     *
     * — so a collection render counts every parent's relation in a single query, no
     * N+1. The `LEFT JOIN` keeps a parent with an empty relation in the result (a
     * `0` count). Rooting on the parent (not the related entity, as
     * {@see fetchRelatedCollection()} does) lets the `GROUP BY` fan the count across
     * the whole page in one statement; the membership semantics are identical (the
     * same backing association). The map is keyed by each parent's wire id (encoded
     * when the parent type declares an id encoder), so the batcher resolves a count
     * back to its parent object at render time.
     *
     * A polymorphic to-many is the same boundary as
     * {@see fetchRelatedCollection()}: its members span entity classes, so there is
     * no single related entity to count — it throws (supply a custom provider).
     *
     * @param list<object> $parents
     *
     * @return array<string, int>
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): array {
        if (\count($relation->relatedTypes()) > 1) {
            throw new \LogicException(\sprintf(
                'The %s does not support counting a polymorphic (morph-to-many) relationship "%s": its members span entity classes and cannot be one grouped COUNT. Supply a custom DataProvider that counts the related members across types.',
                self::class,
                $relation->name(),
            ));
        }

        if ($parents === []) {
            return [];
        }

        $parentClass = $this->entityClassFor($type);
        $parentMetadata = $this->entityManager->getClassMetadata($parentClass);
        $parentIdField = $parentMetadata->getSingleIdentifierFieldName();
        $property = $relation->column() ?? $relation->name();

        // Map each parent's storage id back to its wire id; the IN-clause binds the
        // storage ids, the result keys by the wire id.
        $encoder = $this->idEncoders->encoderFor($type);
        $storageToWire = [];
        foreach ($parents as $parent) {
            $storageKey = $parentMetadata->getIdentifierValues($parent)[$parentIdField] ?? null;
            if (!\is_scalar($storageKey)) {
                continue;
            }

            $wireId = $encoder !== null ? $encoder->encode($storageKey) : (string) $storageKey;
            $storageToWire[(string) $storageKey] = $wireId;
        }

        if ($storageToWire === []) {
            return [];
        }

        $relatedType = $relation->relatedTypes()[0] ?? null;
        $parentIds = \array_keys($storageToWire);

        // A pivot-backed belongsToMany counts the ASSOCIATION-entity rows per parent
        // (so duplicate membership counts each row), grouped over the association
        // entity's parent FK — not the parent's own property, which is not a Doctrine
        // association for a through-entity pivot. A plain to-many counts the related
        // members via a left join on the parent property.
        if ($relatedType !== null && $this->supportsPivot($relatedType, $relation)) {
            $builder = $this->pivotCountQuery($relation, $parents[0], $relatedType, $parentIds);
        } else {
            $builder = $this->entityManager->createQueryBuilder()
                ->select(\sprintf('parent.%s AS parent_id', $parentIdField))
                ->addSelect('COUNT(related) AS related_count')
                ->from($parentClass, 'parent')
                ->leftJoin(\sprintf('parent.%s', $property), 'related')
                ->where(\sprintf('parent.%s IN (:jsonapi_parent_ids)', $parentIdField))
                ->setParameter('jsonapi_parent_ids', $parentIds)
                ->groupBy(\sprintf('parent.%s', $parentIdField));
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->getQuery()->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $storageKey = $row['parent_id'] ?? null;
            if (!\is_scalar($storageKey)) {
                continue;
            }
            $wireId = $storageToWire[(string) $storageKey] ?? null;
            if ($wireId === null) {
                continue;
            }
            $count = $row['related_count'] ?? 0;
            $counts[$wireId] = \is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }

    /**
     * The grouped distinct-member count query for a pivot-backed `belongsToMany` over
     * its association entity: roots on the association entity, scopes to the page of
     * parents by the entity's parent-side `ManyToOne` FK, and groups by that FK so each
     * row is `parent_id => COUNT(DISTINCT far member)`.
     *
     * The count is over **distinct far members** (`COUNT(DISTINCT pivot.<farProperty>)`),
     * NOT association rows — so a member joined to the parent by more than one
     * association row (a track at two positions) contributes ONCE. This matches the
     * related-collection endpoint, which groups one row per distinct far member
     * ({@see countPivot()} counts `DISTINCT` too) and renders deduped linkage: the
     * `?withCount` relationship-object total and the endpoint pagination total are the
     * one consistent `total` semantic the contract requires (bundle ADR 0052).
     *
     * @param list<int|string> $parentIds the parent storage keys (the IN-clause binds them)
     */
    private function pivotCountQuery(RelationInterface $relation, object $parent, string $relatedType, array $parentIds): QueryBuilder
    {
        $relatedClass = $this->entityClassFor($relatedType);
        $association = $this->pivotAssociation($relation, $parent, $relatedClass);

        // Join the parent-side ManyToOne so the count can GROUP BY the parent's id
        // field — DQL cannot GROUP BY an IDENTITY() expression (only a path or a
        // result variable), and grouping by the SELECT alias is not portable.
        $parentClass = $this->entityManager
            ->getClassMetadata($association->entityClass)
            ->getAssociationTargetClass($association->parentProperty);
        $parentIdField = $this->entityManager->getClassMetadata($parentClass)->getSingleIdentifierFieldName();

        return $this->entityManager->createQueryBuilder()
            ->select(\sprintf('parent.%s AS parent_id', $parentIdField))
            // DISTINCT far members so duplicate membership counts once, matching the
            // endpoint's deduped total and rendered linkage (bundle ADR 0052).
            ->addSelect(\sprintf('COUNT(DISTINCT pivot.%s) AS related_count', $association->farProperty))
            ->from($association->entityClass, 'pivot')
            ->innerJoin(\sprintf('pivot.%s', $association->parentProperty), 'parent')
            ->where(\sprintf('parent.%s IN (:jsonapi_parent_ids)', $parentIdField))
            ->setParameter('jsonapi_parent_ids', $parentIds)
            ->groupBy(\sprintf('parent.%s', $parentIdField));
    }

    // --- pivot (belongsToMany association-entity) collection -------------------

    public function supportsPivot(string $relatedType, RelationInterface $relation): bool
    {
        return $this->pivotAssociations !== null
            && $this->pivotAssociations->isPivotRelation($relation)
            && isset($this->entityClassByType[$relatedType]);
    }

    /**
     * The related collection over the pivot's association entity, in ONE DQL
     * statement. The far (related) entity is the OUTER query root, the association
     * entity is joined as `pivot` correlated on its far-side `ManyToOne` and scoped
     * to the parent by its parent-side `ManyToOne`, and each declared pivot field is
     * selected as a scalar alias:
     *
     *     SELECT resource, pivot.<field1> AS pivot_<field1>, …
     *     FROM <FarEntity> resource
     *     INNER JOIN <AssocEntity> pivot WITH pivot.<farProp> = resource
     *     WHERE pivot.<parentProp> = :jsonapi_parent
     *       [AND <related-entity filters on resource>]   -- root alias, shared handler
     *       [AND <pivot filters on pivot.<field>>]        -- pivot alias, this query
     *     ORDER BY [<pivot/related sorts>]
     *     LIMIT/OFFSET
     *
     * Rooting on the far entity lets the shared {@see CriteriaApplier} +
     * {@see DoctrineFilterHandler}/{@see DoctrineSortHandler} apply the related
     * vocabulary on the root exactly as the plain related collection does; pivot
     * keys are split out and applied on the `pivot` alias here. The scalar pivot
     * fields ride each hydrated row (a "mixed" result: `[0 => farEntity,
     * 'pivot_<field>' => value]`), so the per-member map comes from the same pass —
     * no separate read, no page-shortening, and the window applies per far-entity
     * row so pagination is correct.
     *
     * @return PivotCollectionResult<object>
     */
    public function fetchRelatedPivotCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): PivotCollectionResult {
        $builder = $this->pivotQuery($relatedType, $parent, $relation);

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, $relatedType, QueryPurpose::FetchCollection);
        }

        $this->applyPivotCriteria($criteria, $builder, $relation);

        $window = $criteria->window;
        if ($window === null) {
            return $this->pivotResult($this->pivotRows($builder), $relatedType, $relation, null);
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        // A non-countable pivot relation paginates count-FREE, exactly as the plain
        // path does: no COUNT runs; the page is over-fetched by one (limit+1) and the
        // surplus far member signals a further page, driving the count-free page's
        // `next` link without a total (bundle ADR 0052). A countable relation counts
        // the pre-window total as before, emitting total + `last`.
        if (!$relation->isCountable()) {
            return $this->countFreePivotPage($builder, $window, $relatedType, $relation);
        }

        $total = $this->countPivot($builder);
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit);

        return $this->pivotResult($this->pivotRows($builder), $relatedType, $relation, $total);
    }

    /**
     * The count-free page for a non-countable pivot relation — the {@see countFreePage()}
     * analogue over the pivot query. The window is applied with `LIMIT $limit + 1` (one
     * far member past the page), so the surplus row proves a further page exists without
     * any `COUNT`. The pivot query already groups one row per distinct far member (see
     * {@see pivotQuery()}), so the over-fetch and slice are over distinct members. The
     * surplus is dropped before the pivot map is built; the result carries a `null` total
     * with {@see PivotCollectionResult::$windowed} true so the handler builds a count-free
     * page (bundle ADR 0052).
     *
     * @return PivotCollectionResult<object>
     */
    private function countFreePivotPage(
        QueryBuilder $builder,
        OffsetWindow $window,
        string $relatedType,
        RelationInterface $relation,
    ): PivotCollectionResult {
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit + 1);
        $rows = $this->pivotRows($builder);

        $hasMore = \count($rows) > $window->limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $window->limit);
        }

        return $this->pivotResult($rows, $relatedType, $relation, total: null, windowed: true, hasMore: $hasMore);
    }

    /**
     * The pivot map for EVERY member of the parent's pivot relation (no filter, no
     * window) — for the relationship-linkage endpoint, which renders all linkage off
     * the parent.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelatedPivotMap(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
    ): array {
        $builder = $this->pivotQuery($relatedType, $parent, $relation);

        return $this->pivotResult($this->pivotRows($builder), $relatedType, $relation, null)->pivotMap;
    }

    /**
     * The EXISTING pivot meta for the parent's pivot relation — the validation
     * read-seam (ADR 0050). It reuses the same association-entity projection the
     * relationship-linkage endpoint reads ({@see fetchRelatedPivotMap()}): one DQL
     * statement over the association entity, each row's writable/readOnly pivot column
     * cast to its wire value, keyed by the related (far) member's id. The validator
     * folds an existing member's row under the incoming linkage meta so a partial
     * pivot update on an existing member validates in the update (preserved-value)
     * context while a genuinely-new member validates in create context.
     *
     * Returns `[]` when the relation is not a pivot-backed `belongsToMany` this
     * provider can resolve over an association entity — there is then no stored pivot,
     * so every incoming member validates as new (create context).
     *
     * @return array<string, array<string, mixed>> `relatedId => [pivotField => wire value]`
     */
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$this->supportsPivot($relatedType, $relation)) {
            return [];
        }

        return $this->fetchRelatedPivotMap($relatedType, $parent, $relation);
    }

    /**
     * The base pivot query: the far entity rooted at {@see ROOT_ALIAS}, the
     * association entity inner-joined as `pivot` (correlated on its far-side
     * `ManyToOne`, scoped to the parent on its parent-side `ManyToOne`), and each
     * declared pivot field selected as a `pivot_<field>` scalar alias so the values
     * ride each hydrated row.
     *
     * The query `GROUP BY`s the far entity id so it returns exactly ONE row per
     * distinct far member. This keeps pagination correct and the pivot map sound
     * under **duplicate membership** — the same far entity joined to the parent by
     * more than one association-entity row (a track added to a playlist at two
     * positions). Without the grouping the inner join fans that member out one row
     * per pivot row, so a windowed `LIMIT`/`OFFSET` would split a member across pages
     * and the pivot map (keyed by member id) would last-row-win; grouped, the window
     * is over distinct members and each member yields a single representative pivot
     * row. The pivot relation therefore renders **one** membership's values per
     * member: where a member appears more than once the rendered pivot meta reflects
     * a representative row (the contract — pivot meta is a single per-member value
     * set, not a list; ADR 0045).
     */
    private function pivotQuery(string $relatedType, object $parent, RelationInterface $relation): QueryBuilder
    {
        $relatedClass = $this->entityClassFor($relatedType);
        $association = $this->pivotAssociation($relation, $parent, $relatedClass);
        $idField = $this->entityManager->getClassMetadata($relatedClass)->getSingleIdentifierFieldName();

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS)
            ->innerJoin(
                $association->entityClass,
                'pivot',
                'WITH',
                \sprintf('pivot.%s = %s', $association->farProperty, self::ROOT_ALIAS),
            )
            ->andWhere(\sprintf('pivot.%s = :jsonapi_parent', $association->parentProperty))
            ->setParameter('jsonapi_parent', $parent)
            // One row per distinct far member — see the docblock (duplicate membership).
            ->groupBy(\sprintf('%s.%s', self::ROOT_ALIAS, $idField));

        foreach (PivotFields::declaredFor($relation) as $field) {
            // Select the backing column under a `pivot_<name>` alias keyed by the
            // wire name (column defaults to the name, but may differ via storedAs()).
            $builder->addSelect(\sprintf('pivot.%s AS pivot_%s', $field->column() ?? $field->name(), $field->name()));
        }

        return $builder;
    }

    /**
     * Applies the requested filters and sorts to the pivot query, routing each to
     * the right alias: a key declared as a pivot field hits `pivot.<field>`, every
     * other key hits the related-entity root via the shared {@see CriteriaApplier}.
     * Filters compose commutatively, so the related ones go through the shared
     * applier and the pivot ones are appended separately; sorts do NOT compose
     * commutatively, so the whole `ORDER BY` is built in ONE request-ordered pass
     * across both aliases (see {@see applyPivotSorts}) rather than letting the
     * shared applier append all related sorts first.
     */
    private function applyPivotCriteria(CollectionCriteria $criteria, QueryBuilder $builder, RelationInterface $relation): void
    {
        $pivotFields = PivotFields::byName($relation);

        // Filters: a pivot field routes to pivot.<field>; the rest run through the
        // shared applier against the related vocabulary on the root. Splitting the
        // declared vocabulary the same way keeps the unrecognised-key 400 intact —
        // a key in neither set is matched by neither sub-applier.
        $relatedFilters = \array_values(\array_filter(
            $criteria->filters,
            static fn($filter): bool => !isset($pivotFields[$filter->key()]),
        ));

        // Related-entity filters on the ROOT alias, via the shared handler. The
        // requested query parameters are projected to the RELATED filter keys only
        // (pivot keys stripped so the shared applier never tries to match them
        // against the pivot-free related vocabulary and 400) — and to an EMPTY sort,
        // because the ORDER BY is built request-ordered across both aliases below,
        // not by the shared applier (which would append all related sorts first and
        // demote a pivot-first sort; the bug a `?sort=<pivot>,<related>` exposed).
        $relatedParameters = new QueryParameters(
            $criteria->queryParameters->fields,
            $criteria->queryParameters->includes,
            [],
            \array_filter(
                $criteria->queryParameters->filter,
                static fn($key): bool => !isset($pivotFields[$key]),
                \ARRAY_FILTER_USE_KEY,
            ),
            $criteria->queryParameters->pagination,
        );

        $relatedCriteria = new CollectionCriteria(
            $relatedParameters,
            $relatedFilters,
            // No sorts on the shared applier — the request sort is applied below in
            // request order. An empty default carried for the same reason.
            [],
            null,
            [],
        );
        $this->applier->apply($relatedCriteria, $builder, $this->filterHandler, $this->sortHandler);

        $this->applyPivotFilters($criteria, $builder, $pivotFields);

        // The full ORDER BY, request-ordered across the pivot and root aliases. The
        // related sort vocabulary is the criteria's declared sorts minus the pivot
        // keys, and the default sort applies only when no `?sort` was requested.
        $relatedSorts = \array_values(\array_filter(
            $criteria->sorts,
            static fn($sort): bool => !isset($pivotFields[$sort->key()]),
        ));
        $defaultSort = \array_values(\array_filter(
            $criteria->defaultSort,
            static fn(SortDirective $directive): bool => !isset($pivotFields[$directive->sort->key()]),
        ));
        $this->applyPivotSorts($criteria, $builder, $pivotFields, $relatedSorts, $defaultSort);
    }

    /**
     * Applies the requested pivot-field filters on the `pivot` alias. A pivot filter
     * is an equality match on the field's backing column, rebuilt here on the
     * `pivot` alias because the shared handler only ever targets the query root; the
     * value is coerced through the field's own cast.
     *
     * @param array<string, FieldInterface> $pivotFields the declared pivot fields keyed by wire name
     */
    private function applyPivotFilters(CollectionCriteria $criteria, QueryBuilder $builder, array $pivotFields): void
    {
        foreach ($criteria->queryParameters->filter as $key => $value) {
            $key = (string) $key;
            $field = $pivotFields[$key] ?? null;
            if ($field === null) {
                continue;
            }

            $placeholder = 'jsonapi_pivot_filter_' . \count($builder->getParameters());
            $builder
                ->andWhere(\sprintf('pivot.%s = :%s', $field->column() ?? $field->name(), $placeholder))
                ->setParameter($placeholder, PivotFields::cast($value, $field));
        }
    }

    /**
     * Builds the whole `ORDER BY` in ONE pass, in the request's exact `sort`
     * directive order, routing each field to the correct alias so the precedence
     * matches the client's list: a pivot field appends `pivot.<field>`, every other
     * field resolves its column from the related sort vocabulary and appends
     * `resource.<column>` on the root. So `?sort=position,title` orders by
     * `pivot.position` then `resource.title`, and `?sort=title,position` flips that —
     * the request-first directive is always the most significant key (the bug a
     * pivot-first sort exposed when related sorts were appended first).
     *
     * Sorting semantics match the shared {@see CriteriaApplier}: with no `?sort` the
     * related default order applies (pivot fields declare no default direction and
     * have no related column, so a pivot default does not belong); a requested field
     * in neither the pivot set nor the related vocabulary is a 400
     * ({@see SortParamUnrecognized}), and requesting any sort when neither vocabulary
     * exists is a 400 ({@see SortingUnsupported}).
     *
     * @param array<string, FieldInterface> $pivotFields  the declared pivot fields keyed by wire name
     * @param list<SortInterface>            $relatedSorts the related sort vocabulary (criteria sorts minus pivot keys)
     * @param list<SortDirective>            $defaultSort  the related default order, applied only when no `?sort` is requested
     */
    private function applyPivotSorts(
        CollectionCriteria $criteria,
        QueryBuilder $builder,
        array $pivotFields,
        array $relatedSorts,
        array $defaultSort,
    ): void {
        $requested = $criteria->queryParameters->sort;

        if ($requested === []) {
            // No `?sort`: fall back to the related default order through the shared
            // handler (validated against the related vocabulary exactly as a
            // requested related sort is).
            if ($defaultSort !== []) {
                $this->sortHandler->apply($this->validateDefaults($defaultSort, $relatedSorts), $builder);
            }

            return;
        }

        if ($pivotFields === [] && $relatedSorts === []) {
            throw new SortingUnsupported();
        }

        foreach ($requested as $field) {
            $descending = \str_starts_with($field, '-');
            $key = $descending ? \substr($field, 1) : $field;

            $pivotField = $pivotFields[$key] ?? null;
            if ($pivotField !== null) {
                $builder->addOrderBy(\sprintf('pivot.%s', $pivotField->column() ?? $pivotField->name()), $descending ? 'DESC' : 'ASC');

                continue;
            }

            // A related sort field: resolve its declared column and order on the
            // root through the shared handler (one directive at a time preserves the
            // request order across both aliases). A field in neither vocabulary is
            // unrecognised — the 400 the shared applier would have raised.
            $sort = $this->relatedSortFor($relatedSorts, $key) ?? throw new SortParamUnrecognized($key);
            $this->sortHandler->apply([new SortDirective($sort, $descending)], $builder);
        }
    }

    /**
     * Validates each default directive names a declared related sort (a server-config
     * error otherwise, exactly as the shared {@see CriteriaApplier} validates a
     * default), returning them unchanged for the handler.
     *
     * @param list<SortDirective> $defaultSort
     * @param list<SortInterface> $relatedSorts
     *
     * @return list<SortDirective>
     *
     * @throws SortParamUnrecognized when a default names an undeclared related sort
     */
    private function validateDefaults(array $defaultSort, array $relatedSorts): array
    {
        foreach ($defaultSort as $directive) {
            if ($this->relatedSortFor($relatedSorts, $directive->sort->key()) === null) {
                throw new SortParamUnrecognized($directive->sort->key());
            }
        }

        return $defaultSort;
    }

    /**
     * The declared related sort whose key matches `$key`, or null when none does.
     *
     * @param list<SortInterface> $relatedSorts
     */
    private function relatedSortFor(array $relatedSorts, string $key): ?SortInterface
    {
        foreach ($relatedSorts as $sort) {
            if ($sort->key() === $key) {
                return $sort;
            }
        }

        return null;
    }

    /**
     * Runs the pivot query and returns the "mixed" result rows — each a
     * `[0 => farEntity, 'pivot_<field>' => value, …]` map (the far entity hydrated at
     * index 0, the scalar pivot fields under their `pivot_<field>` aliases).
     *
     * @return list<array<int|string, mixed>>
     */
    private function pivotRows(QueryBuilder $builder): array
    {
        /** @var list<array<int|string, mixed>> $rows */
        $rows = $builder->getQuery()->getResult();

        return $rows;
    }

    /**
     * Builds the {@see PivotCollectionResult} from the query rows: the far entities
     * (row index 0) are the items, and the pivot map is `wireId => [field => typed
     * value]` read from each row's `pivot_<field>` scalar aliases and cast per the
     * relation's declared field types.
     *
     * @param list<array<int|string, mixed>> $rows
     *
     * @return PivotCollectionResult<object>
     */
    private function pivotResult(
        array $rows,
        string $relatedType,
        RelationInterface $relation,
        ?int $total,
        bool $windowed = false,
        bool $hasMore = false,
    ): PivotCollectionResult {
        $pivotFields = PivotFields::byName($relation);
        $encoder = $this->idEncoders->encoderFor($relatedType);
        $relatedMetadata = $this->entityManager->getClassMetadata($this->entityClassFor($relatedType));
        $idField = $relatedMetadata->getSingleIdentifierFieldName();

        $items = [];
        $pivotMap = [];
        foreach ($rows as $row) {
            $far = $row[0] ?? null;
            if (!\is_object($far)) {
                continue;
            }
            $items[] = $far;

            $identifierValues = $this->entityManager->getClassMetadata($far::class)->getIdentifierValues($far);
            $storageKey = $identifierValues[$idField] ?? null;
            if ($storageKey === null) {
                continue;
            }

            if ($encoder !== null) {
                $wireId = $encoder->encode($storageKey);
            } elseif (\is_scalar($storageKey)) {
                $wireId = (string) $storageKey;
            } else {
                continue;
            }

            $values = [];
            foreach ($pivotFields as $name => $field) {
                // The scalar was selected under the `pivot_<name>` alias (keyed by
                // wire name) in pivotQuery(); cast it through the field's own type.
                $values[$name] = PivotFields::cast($row['pivot_' . $name] ?? null, $field);
            }

            $pivotMap[$wireId] = $values;
        }

        return new PivotCollectionResult($items, $pivotMap, $total, $windowed, $hasMore);
    }

    /**
     * The pre-window total of the pivot query: the same builder re-selected as a
     * `COUNT(DISTINCT …)` of the far-entity root, ordering and grouping dropped, no
     * window. `DISTINCT` so the total counts distinct far members rather than joined
     * pivot rows — the page is grouped to one row per member (see {@see pivotQuery}),
     * so the total must match it under duplicate membership (a member joined by more
     * than one association-entity row). The scalar pivot selects and the `GROUP BY`
     * must be cleared too — a `COUNT` query carries a single ungrouped select.
     */
    private function countPivot(QueryBuilder $builder): int
    {
        $counter = clone $builder;
        $counter->resetDQLPart('orderBy');
        $counter->resetDQLPart('groupBy');
        $counter->select(\sprintf('COUNT(DISTINCT %s)', self::ROOT_ALIAS));

        $total = $counter->getQuery()->getSingleScalarResult();

        return \is_numeric($total) ? (int) $total : 0;
    }

    /**
     * Resolves the relation's association entity, asserting the resolver is wired
     * (it always is under Doctrine — `supportsPivot()` already gated on it).
     *
     * @param class-string $relatedClass
     */
    private function pivotAssociation(RelationInterface $relation, object $parent, string $relatedClass): PivotAssociation
    {
        \assert($this->pivotAssociations !== null);

        return $this->pivotAssociations->resolve($relation, $parent, $relatedClass);
    }

    /**
     * Batch eager-loads the read's effective `?include` tree (explicit includes or
     * a resource's default-include fallback) so the included relationships do not
     * N+1, delegating to the injected {@see IncludePreloader}. A no-op when the
     * `shipmonk/doctrine-entity-preloader` library is absent (the preloader is then
     * null) — the includes render lazily (ADR 0035).
     */
    public function preloadIncludes(iterable $entities, string $type, JsonApiRequestInterface $request): void
    {
        $this->preloader?->preload($entities, $type, $request);
    }

    /**
     * Disables include preloading on this provider, returning the previously
     * installed {@see IncludePreloader} (or `null`) so a caller can restore it.
     * Preloading is a pure optimization, so turning it off only changes how the
     * includes are loaded (lazily), never what is rendered — the property the
     * conformance suite asserts by comparing the document with and without it.
     *
     * @internal a test/diagnostic seam, not part of the provider contract
     */
    public function disableIncludePreloading(): ?IncludePreloader
    {
        $previous = $this->preloader;
        $this->preloader = null;

        return $previous;
    }

    /**
     * @return list<object>
     */
    private function items(QueryBuilder $builder): array
    {
        /** @var list<object> */
        return $builder->getQuery()->getResult();
    }

    /**
     * The total of the filtered collection: the same builder re-selected as a
     * `COUNT` of the root entity, with ordering dropped (it cannot change the
     * count) and no window applied.
     */
    private function count(QueryBuilder $builder): int
    {
        $counter = clone $builder;
        $counter->resetDQLPart('orderBy');
        $counter->select(\sprintf('COUNT(%s)', self::ROOT_ALIAS));

        $total = $counter->getQuery()->getSingleScalarResult();

        return \is_numeric($total) ? (int) $total : 0;
    }

    /**
     * The owning-side field on the related entity for an inverse-side association
     * on the parent (the single-valued FK an inverse OneToMany is `mappedBy`), or
     * `null` when the parent's association is itself the owning side (or
     * many-to-many — no single-valued inverse FK on the related entity to scope
     * by). Mirrors the persister's resolver of the same name.
     */
    private function inverseOwningField(object $parent, string $property): ?string
    {
        $metadata = $this->entityManager->getClassMetadata($parent::class);
        if (!$metadata->hasAssociation($property)) {
            return null;
        }

        $mapping = $metadata->getAssociationMapping($property);

        if ($mapping->isOwningSide()) {
            return null;
        }

        // `mappedBy` lives on the inverse-side mapping; read it through the
        // mapping's array access so the lookup is robust across the ORM 3 mapping
        // class hierarchy.
        $mappedBy = $mapping['mappedBy'] ?? null;

        return \is_string($mappedBy) ? $mappedBy : null;
    }

    /**
     * @return class-string
     */
    private function entityClassFor(string $type): string
    {
        return $this->entityClassByType[$type]
            ?? throw new \LogicException(\sprintf('No Doctrine entity class is mapped for JSON:API type "%s".', $type));
    }

    /**
     * The extensions whose {@see DoctrineExtensionInterface::supports()} is
     * true for `$type`, preserving the injected (priority) order.
     *
     * @return list<DoctrineExtensionInterface>
     */
    private function extensionsFor(string $type): array
    {
        return \array_values(\array_filter(
            $this->extensions,
            static fn(DoctrineExtensionInterface $extension): bool => $extension->supports($type),
        ));
    }
}
