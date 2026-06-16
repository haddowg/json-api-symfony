<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CriteriaApplier;
use haddowg\JsonApiBundle\DataProvider\RelatedBatch;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;

/**
 * The bounded ROW_NUMBER windowed-include batch (bundle ADR 0065/0066): ONE native SQL
 * query per relation windows a whole page of parents' related collections to ~limit
 * rows each, returning the REAL per-parent total — the fix for the 6a batch
 * ({@see DoctrineDataProvider::fetchRelatedCollectionBatch()}) materialising every
 * parent's WHOLE related set then slicing in PHP (a massive over-fetch on a large
 * relation, with a page-size total rather than the true cardinality).
 *
 * The shape is a derived-table window (portable across every targeted engine: MySQL
 * 8 / MariaDB 10.2 / SQLite 3.25 / any PostgreSQL — a CTE buys nothing here):
 *
 *     SELECT * FROM (
 *       SELECT w.*,
 *              ROW_NUMBER() OVER (PARTITION BY w.<parentAlias> ORDER BY <sort>, <pk>) AS jsonapi_rn,
 *              COUNT(*)     OVER (PARTITION BY w.<parentAlias>)                        AS jsonapi_total
 *       FROM ( <inner DQL SQL> ) w
 *     ) x
 *     WHERE x.jsonapi_rn <= ?
 *
 * **The inner query is the EXACT same DQL the related-collection endpoint runs.** Rather
 * than hand-translate the filter vocabulary into a parallel native handler, the inner
 * scoped + FILTERED query is built as a normal DQL {@see QueryBuilder} — the parent-scope
 * predicate, plus the shared {@see CriteriaApplier} driving the {@see DoctrineFilterHandler}
 * (the #1 DQL filter executor the related endpoint and the in-memory witness both mirror).
 * Its SQL is read via {@see Query::getSQL()} and wrapped with the window functions; the
 * generated SQL column aliases (the parent discriminator, the sort columns, the pk) are
 * READ off the {@see ResultSetMapping} Doctrine built for that query (never predicted), so
 * a filtered windowed include is witness-equivalent FOR FREE — it IS the same executor,
 * just wrapped for the window (bundle ADR 0066). The sort is NOT applied to the inner
 * query (the outer window re-orders); the window ORDER BY is emitted from the read aliases.
 *
 * Two discriminator shapes mirror {@see RelationScope}:
 *  - **Inverse-FK OneToMany** — the related entity is the inner DQL ROOT, scoped by the
 *    owning FK `IN` the page with the FK projected as `jsonapi_parent_id`; the related
 *    entity hydrates INLINE (a member belongs to one parent, so no cross-partition
 *    root-entity dedup);
 *  - **Owning-side / many-to-many** — the PARENT is the inner DQL root, the related
 *    collection is joined as `related`, and the query selects the scalar `(parentId,
 *    relatedId)` PAIRS plus each sort column as a scalar (the ORM object hydrator would
 *    dedup a member shared across parents and lose a partition). The provider id-loads the
 *    distinct related entities by id in ONE further query and re-associates per row.
 *
 * Hydration is a Doctrine `NativeQuery` reusing the INNER query's {@see ResultSetMapping}
 * (it already maps every entity column + the projected scalars) extended with the rn/total
 * scalars; the outer `SELECT *` re-exposes the inner generated aliases so the RSM still
 * maps them. Parameters are rebound by ORDINAL — {@see Query::getSQL()} emits positional
 * `?` placeholders, so the inner DQL params are bound at the SQL positions
 * {@see ParserResult::getParameterMappings()} reports (carrying each param's DBAL type),
 * and the row cap binds at the next ordinal.
 *
 * Bounded + true total: every returned row carries `jsonapi_rn <= :limit` (so at most
 * `limit` rows per parent are fetched), and `jsonapi_total` = `COUNT(*) OVER` the FULL
 * partition (independent of the limit) — the real pre-window per-parent cardinality
 * that feeds the relationship-pagination total. A non-countable relation emits NO total
 * (the count-free contract): it bounds `jsonapi_rn <= :limit + 1` instead and reads the
 * surplus row as the `hasMore` probe.
 *
 * Determinism: a partition scan has no inherent order, so the ORDER BY appends a PK
 * tiebreak (`<sort cols>, r.<pk> ASC`) — the SAME tiebreak the in-memory witness now
 * appends on its batch/window path ({@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider}),
 * so the two are provably identical on ties. NULL ordering reuses the keyset path's
 * portable `CASE WHEN c IS NULL` term so a nullable sort matches PHP's `null <=> value`.
 *
 * The native path now serves a FILTERED and an unfiltered windowed include alike (bundle
 * ADR 0066). Only an extended related type, a polymorphic/composite shape, or
 * `window_functions: off` route to the per-parent bounded fallback in the provider.
 */
final class WindowedRelationBatch
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdEncoderResolver $idEncoders,
        private readonly CriteriaApplier $applier,
        private readonly DoctrineFilterHandler $filterHandler,
        private readonly DoctrineSortHandler $sortHandler,
    ) {}

    /**
     * Runs the native windowed batch for one to-many relation across the page of
     * parents and returns the per-parent windowed {@see CollectionResult}s keyed by
     * parent wire id.
     *
     * @param list<object> $parents     the page of parents (non-empty)
     * @param class-string $relatedClass
     *
     * @throws \RuntimeException when the native query fails (an engine without window
     *                           functions on the default `on` setting — set
     *                           `json_api.doctrine.window_functions: false`)
     */
    public function fetch(
        string $parentType,
        array $parents,
        string $relatedType,
        string $relatedClass,
        RelationInterface $relation,
        CollectionCriteria $criteria,
    ): RelatedBatch {
        $window = $criteria->window;
        \assert($window instanceof \haddowg\JsonApi\Pagination\OffsetWindow);

        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);
        $parentMetadata = $this->entityManager->getClassMetadata($parents[0]::class);
        /** @var class-string $parentClass */
        $parentClass = $parentMetadata->getName();

        $countable = $relation->isCountable();
        // A non-countable relation is count-free: bound rn <= limit+1 and read the
        // surplus row per parent as the hasMore probe. A countable one bounds rn <=
        // offset+limit (the offset window's last row) and reads jsonapi_total.
        $rowCap = $countable ? ($window->offset + $window->limit) : ($window->offset + $window->limit + 1);

        // The inner RSM types the jsonapi_parent_id scalar from the IDENTITY / field
        // projection itself, so the batch never re-declares the parent id type here.
        $shape = $this->resolveShape($parentMetadata, $relatedMetadata, $relation, $parentClass);
        $parentIds = $this->parentStorageIds($parents, $parentMetadata);

        // The sort directives the window ORDER BY emits — resolved (and validated) once,
        // exactly as the per-parent path resolves them, so the native path selects the
        // SAME members as the witness. The sort is NOT applied to the inner DQL (the
        // outer window re-orders); the window ORDER BY references the read aliases.
        $sortDirectives = $this->sortDirectives($criteria);

        // Build the inner scoped + FILTERED DQL query with the EXISTING machinery: the
        // parent-scope predicate, plus the shared CriteriaApplier driving the #1 DQL
        // filter executor. Apply ONLY the filters (a sort-stripped criteria), so the
        // inner query carries no ORDER BY to strip.
        $builder = $this->innerQuery($shape, $relatedClass, $parentIds, $sortDirectives, $criteria);
        $query = $builder->getQuery();
        $innerSql = $query->getSQL();
        \assert(\is_string($innerSql));

        $innerRsm = $this->reflectResultSetMapping($query);
        $parameterMappings = $this->reflectParameterMappings($query);

        $parentAlias = $this->scalarAlias($innerRsm, BatchScope::PARENT_DISCRIMINATOR_ALIAS);
        $orderBy = $this->orderBy($innerRsm, $relatedMetadata, $sortDirectives, $shape);

        $totalSelect = $countable
            ? \sprintf(', COUNT(*) OVER (PARTITION BY w.%s) AS jsonapi_total', $parentAlias)
            : '';

        // Strip a trailing inner ORDER BY defensively (the inner DQL carries no sort, but
        // a related extension or the filter handler never adds one either — this keeps the
        // wrap robust). The window re-orders, so a leftover inner ORDER BY is redundant.
        $strippedInner = (string) \preg_replace('/\s+ORDER BY .*$/is', '', $innerSql);

        $sql = \sprintf(
            'SELECT * FROM (SELECT w.*, ROW_NUMBER() OVER (PARTITION BY w.%s ORDER BY %s) AS jsonapi_rn%s FROM (%s) w) x WHERE x.jsonapi_rn <= ?',
            $parentAlias,
            $orderBy,
            $totalSelect,
            $strippedInner,
        );

        // Reuse the inner RSM (it already maps every entity column + the projected
        // scalars) and append the window scalars; the outer SELECT * re-exposes the inner
        // aliases so the RSM still maps them.
        $innerRsm->addScalarResult('jsonapi_rn', 'rn', 'integer');
        if ($countable) {
            $innerRsm->addScalarResult('jsonapi_total', 'total', 'integer');
        }

        $native = $this->entityManager->createNativeQuery($sql, $innerRsm);
        $this->rebindParameters($native, $query, $parameterMappings, $rowCap);

        /** @var list<array<int|string, mixed>> $rows */
        $rows = $this->runOrThrow($native);

        // The join-table shape's rows carry the related id scalar, not the entity — load
        // the distinct related entities by id in ONE further query and re-associate per row.
        $relatedById = $shape->isJoinTable()
            ? $this->loadRelatedById($relatedClass, $relatedMetadata, $rows)
            : [];

        return $this->partition($rows, $parentType, $window, $countable, $shape->isJoinTable(), $relatedById);
    }

    /**
     * Resolves the inner-query shape for the relation: the inverse-FK fast-path (the
     * related entity carries the owning FK) or the join-table / many-to-many shape (the
     * parent is the inner root, the related collection joined). Carries the field/class
     * names the inner DQL builder and the orderBy need. Mirrors {@see RelationScope}'s
     * two branches.
     *
     * @param ClassMetadata<object> $parentMetadata
     * @param ClassMetadata<object> $relatedMetadata
     * @param class-string          $parentClass
     */
    private function resolveShape(
        ClassMetadata $parentMetadata,
        ClassMetadata $relatedMetadata,
        RelationInterface $relation,
        string $parentClass,
    ): WindowShape {
        $property = $relation->column() ?? $relation->name();

        // Inverse-FK fast-path: a single-valued inverse association (the related entity
        // carries the owning FK). The related entity is the inner DQL root, scoped by the
        // owning FK; the FK is projected as the parent discriminator.
        $owningField = $this->inverseOwningField($parentMetadata, $property);
        if ($owningField !== null && $relatedMetadata->isSingleValuedAssociation($owningField)) {
            return WindowShape::inverseFk($owningField);
        }

        // Owning-side / many-to-many: the parent is the inner root, the related collection
        // joined; the inner DQL selects the (parentId, relatedId) scalar pairs + the sort
        // columns as scalars, partitioned by the parent id. The provider only routes a
        // batchable association here, so the property IS a parent association.
        if (!$parentMetadata->hasAssociation($property)) {
            throw new \LogicException(\sprintf(
                'The %s cannot natively window relationship "%s": it is not an inverse-FK to-many nor an owning many-to-many. Supply a custom DataProvider or set json_api.doctrine.window_functions: false.',
                self::class,
                $relation->name(),
            ));
        }

        return WindowShape::joinTable(
            property: $property,
            parentClass: $parentClass,
            parentIdField: $parentMetadata->getSingleIdentifierFieldName(),
            relatedIdField: $relatedMetadata->getSingleIdentifierFieldName(),
        );
    }

    /**
     * Builds the inner scoped + FILTERED DQL query (no sort — the outer window re-orders).
     * The inverse-FK shape roots on the related entity; the join-table shape roots on the
     * parent and selects the (parentId, relatedId) scalar pairs + the sort columns as
     * scalars. Either way the shared {@see CriteriaApplier} pushes the criteria FILTERS
     * down on the related alias through the #1 DQL filter executor (sort stripped).
     *
     * @param class-string        $relatedClass
     * @param list<string>        $parentIds
     * @param list<SortDirective> $sortDirectives
     */
    private function innerQuery(
        WindowShape $shape,
        string $relatedClass,
        array $parentIds,
        array $sortDirectives,
        CollectionCriteria $criteria,
    ): QueryBuilder {
        if (!$shape->isJoinTable()) {
            $owningField = $shape->owningField;
            \assert($owningField !== null);

            $builder = $this->entityManager
                ->getRepository($relatedClass)
                ->createQueryBuilder('related')
                ->addSelect(\sprintf('IDENTITY(related.%s) AS %s', $owningField, BatchScope::PARENT_DISCRIMINATOR_ALIAS))
                ->andWhere(\sprintf('IDENTITY(related.%s) IN (:jsonapi_parent_ids)', $owningField))
                ->setParameter('jsonapi_parent_ids', $parentIds, ArrayParameterType::STRING);

            return $this->applier->apply($this->filtersOnly($criteria), $builder, $this->filterHandler, $this->sortHandler, 'related');
        }

        $property = $shape->property;
        $relatedIdField = $shape->relatedIdField;
        $parentClass = $shape->parentClass;
        $parentIdField = $shape->parentIdField;
        \assert($property !== null && $relatedIdField !== null && $parentClass !== null && $parentIdField !== null);

        $builder = $this->entityManager->createQueryBuilder()
            ->select(\sprintf('parent.%s AS %s', $parentIdField, BatchScope::PARENT_DISCRIMINATOR_ALIAS))
            ->addSelect(\sprintf('related.%s AS %s', $relatedIdField, BatchScope::RELATED_DISCRIMINATOR_ALIAS))
            ->from($parentClass, 'parent')
            ->innerJoin(\sprintf('parent.%s', $property), 'related')
            ->where('parent.' . $parentIdField . ' IN (:jsonapi_parent_ids)')
            ->setParameter('jsonapi_parent_ids', $parentIds, ArrayParameterType::STRING);

        // The pair shape selects scalars, so the outer window cannot ORDER BY a related
        // column it never projected — project each sort field as a scalar the orderBy
        // reads back off the RSM.
        foreach ($sortDirectives as $i => $directive) {
            $sort = $directive->sort;
            \assert($sort instanceof SortByField);
            $builder->addSelect(\sprintf('related.%s AS jsonapi_sort_%d', $sort->column, $i));
        }

        return $this->applier->apply($this->filtersOnly($criteria), $builder, $this->filterHandler, $this->sortHandler, 'related');
    }

    /**
     * The window ORDER BY: the resolved sort columns (each a portable NULL=smallest
     * `CASE WHEN c IS NULL` term then the column) over the GENERATED SQL aliases READ off
     * the inner query's {@see ResultSetMapping}, plus a trailing PK tiebreak so the
     * partition scan is deterministic — the same tiebreak the witness now appends.
     *
     * @param ClassMetadata<object> $relatedMetadata
     * @param list<SortDirective>   $sortDirectives
     */
    private function orderBy(
        ResultSetMapping $innerRsm,
        ClassMetadata $relatedMetadata,
        array $sortDirectives,
        WindowShape $shape,
    ): string {
        $terms = [];
        foreach ($sortDirectives as $i => $directive) {
            $sort = $directive->sort;
            \assert($sort instanceof SortByField);

            // Read the generated SQL alias of the sort column: for the inverse-FK shape the
            // entity field on the `related` alias; for the pair shape the projected scalar.
            $column = $shape->isJoinTable()
                ? 'w.' . $this->scalarAlias($innerRsm, \sprintf('jsonapi_sort_%d', $i))
                : 'w.' . $this->fieldAlias($innerRsm, 'related', $sort->column);
            $direction = $directive->descending ? 'DESC' : 'ASC';

            // Portable NULL placement matching PHP `null <=> value` (null smallest on ASC):
            // a leading CASE WHEN c IS NULL term in the same direction, then the column.
            $terms[] = \sprintf('CASE WHEN %s IS NULL THEN 1 ELSE 0 END %s', $column, $direction);
            $terms[] = \sprintf('%s %s', $column, $direction);
        }

        // The deterministic PK tiebreak: for the inverse-FK shape the related entity's pk
        // column (read off the RSM); for the pair shape the projected related-id scalar.
        $pk = $shape->isJoinTable()
            ? 'w.' . $this->scalarAlias($innerRsm, BatchScope::RELATED_DISCRIMINATOR_ALIAS)
            : 'w.' . $this->fieldAlias($innerRsm, 'related', $relatedMetadata->getSingleIdentifierFieldName());
        $terms[] = $pk . ' ASC';

        return \implode(', ', $terms);
    }

    /**
     * The generated SQL column alias of `$dqlAlias.$field` off the inner query's RSM,
     * read off the RSM's public field-mapping maps (`fieldMappings`: sqlColumnAlias =>
     * field; `columnOwnerMap`: sqlColumnAlias => dqlAlias). This deliberately avoids the
     * `getColumnAliasByField()`/`hasColumnAliasByField()` accessors, which doctrine/orm
     * only added after 3.0, so the bundle keeps its `doctrine/orm: ^3.0` floor. A column
     * the wrap must reference but the inner query never selected is a logic error.
     */
    private function fieldAlias(ResultSetMapping $rsm, string $dqlAlias, string $field): string
    {
        foreach ($rsm->fieldMappings as $columnAlias => $mappedField) {
            if ($mappedField === $field && ($rsm->columnOwnerMap[$columnAlias] ?? null) === $dqlAlias) {
                return $columnAlias;
            }
        }

        throw new \LogicException(\sprintf(
            'The %s cannot resolve the generated SQL alias for "%s.%s" off the inner query; the field is not selected.',
            self::class,
            $dqlAlias,
            $field,
        ));
    }

    /**
     * The generated SQL column alias a projected scalar result (`$resultAlias`) maps to,
     * by REVERSE lookup on the public `scalarMappings` (sqlColAlias => resultAlias) — the
     * parent/related discriminators and the sort scalars are scalar projections, not entity
     * fields, so they are not in the field-alias map.
     */
    private function scalarAlias(ResultSetMapping $rsm, string $resultAlias): string
    {
        $alias = \array_search($resultAlias, $rsm->scalarMappings, true);
        if (!\is_string($alias)) {
            throw new \LogicException(\sprintf(
                'The %s cannot resolve the generated SQL alias for the projected scalar "%s" off the inner query.',
                self::class,
                $resultAlias,
            ));
        }

        return $alias;
    }

    /**
     * A sort-stripped, window-less copy of `$criteria` so the shared applier applies only
     * its FILTERS on the inner query (the outer window owns the order). Mirrors
     * {@see DoctrineDataProvider::filtersOnly()}.
     */
    private function filtersOnly(CollectionCriteria $criteria): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters(
                $criteria->queryParameters->fields,
                $criteria->queryParameters->includes,
                sort: [],
                filter: $criteria->queryParameters->filter,
                pagination: $criteria->queryParameters->pagination,
            ),
            $criteria->filters,
            sorts: [],
            window: null,
            defaultSort: [],
            aliasOf: $criteria->aliasOf,
        );
    }

    /**
     * Reflects the inner query's {@see ResultSetMapping} — there is no public accessor
     * (`Query::getResultSetMapping()` is protected at every layer; only
     * `setResultSetMapping()` is public), so the supported-but-internal route is reflection
     * on the protected method. `getSQL()` already triggered + cached the parse, so the
     * reflected RSM is the SAME parse (no double parse, no extra query). Pinned to the ORM
     * 3.x line (bundle ADR 0066).
     */
    private function reflectResultSetMapping(Query $query): ResultSetMapping
    {
        $this->assertReflectable('getResultSetMapping');

        $method = new \ReflectionMethod(Query::class, 'getResultSetMapping');
        $method->setAccessible(true);
        $rsm = $method->invoke($query);
        \assert($rsm instanceof ResultSetMapping);

        return $rsm;
    }

    /**
     * Reflects the inner query's named-DQL-param => SQL-position map (0-based positions)
     * off the {@see ParserResult} of the private `Query::parse()` (the SAME cached parse
     * `getSQL()` used). `getSQL()` emits POSITIONAL `?` placeholders, so the named DQL
     * params are rebound by ordinal at these positions (bundle ADR 0066).
     *
     * @return array<string, list<int>>
     */
    private function reflectParameterMappings(Query $query): array
    {
        $this->assertReflectable('parse');

        $method = new \ReflectionMethod(Query::class, 'parse');
        $method->setAccessible(true);
        $parserResult = $method->invoke($query);
        \assert($parserResult instanceof ParserResult);

        /** @var array<string, list<int>> $mappings */
        $mappings = $parserResult->getParameterMappings();

        return $mappings;
    }

    /**
     * Fast-fails with an actionable message if a future Doctrine release drops the
     * internal {@see Query} method this batch reflects on (the supported workaround is
     * the portable per-parent fallback). Without this guard a missing method surfaces
     * as a bare {@see \ReflectionException} that never names the cause or the fix.
     */
    private function assertReflectable(string $method): void
    {
        if (\method_exists(Query::class, $method)) {
            return;
        }

        throw new \LogicException(\sprintf(
            'The windowed-include row-number batch reads the inner query through the internal %s::%s(), which this Doctrine ORM version no longer exposes. Set the bundle config "json_api.doctrine.window_functions" to "off" to use the portable per-parent fallback, or pin doctrine/orm to a compatible release (bundle ADR 0066).',
            Query::class,
            $method,
        ));
    }

    /**
     * Rebinds the inner DQL query's parameters onto the native query by ORDINAL (the
     * wrapped SQL is positional `?`): each ORM parameter binds at the 1-based ordinal of
     * each SQL position {@see reflectParameterMappings()} reports, carrying its DBAL type
     * (so an `ArrayParameterType` IN-list and a `STRING` LIKE bind correctly). The row cap
     * (`:limit`, the outer `WHERE jsonapi_rn <= ?`) binds at the next ordinal.
     *
     * @param array<string, list<int>> $parameterMappings
     */
    private function rebindParameters(\Doctrine\ORM\NativeQuery $native, Query $query, array $parameterMappings, int $rowCap): void
    {
        $maxPosition = -1;
        foreach ($query->getParameters() as $parameter) {
            $positions = $parameterMappings[$parameter->getName()] ?? [];
            foreach ($positions as $position) {
                $native->setParameter($position + 1, $parameter->getValue(), $this->parameterType($parameter->getType()));
                $maxPosition = \max($maxPosition, $position);
            }
        }

        // The row cap binds at the next ordinal (max SQL position + 2, 1-based).
        $native->setParameter($maxPosition + 2, $rowCap);
    }

    /**
     * Narrows a DQL parameter's bound type (declared `mixed` on
     * {@see \Doctrine\ORM\Query\Parameter::getType()}) to the union
     * {@see \Doctrine\ORM\AbstractQuery::setParameter()} accepts, so the rebind carries the
     * exact DBAL type (an `ArrayParameterType` IN-list, a `ParameterType`/named string
     * type). An unexpected type binds untyped (null) — DBAL then infers from the value.
     */
    private function parameterType(mixed $type): ArrayParameterType|\Doctrine\DBAL\ParameterType|string|int|null
    {
        if ($type instanceof ArrayParameterType || $type instanceof \Doctrine\DBAL\ParameterType) {
            return $type;
        }

        return \is_string($type) || \is_int($type) ? $type : null;
    }

    /**
     * Loads the distinct related entities of `$relatedClass` for the join-table shape's
     * `jsonapi_related_id` scalars in ONE `id IN (…)` query, keyed by stringified id — so
     * the scalar-pair rows re-associate to managed entities without the ORM cross-partition
     * dedup. Empty when there are no related ids.
     *
     * @param class-string                   $relatedClass
     * @param ClassMetadata<object>          $relatedMetadata
     * @param list<array<int|string, mixed>> $rows
     *
     * @return array<string, object> `relatedStorageId => entity`
     */
    private function loadRelatedById(string $relatedClass, ClassMetadata $relatedMetadata, array $rows): array
    {
        $idField = $relatedMetadata->getSingleIdentifierFieldName();

        $ids = [];
        foreach ($rows as $row) {
            $relatedId = $row[BatchScope::RELATED_DISCRIMINATOR_ALIAS] ?? null;
            if (\is_scalar($relatedId)) {
                $ids[(string) $relatedId] = $relatedId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $loaded = $this->entityManager->getRepository($relatedClass)
            ->createQueryBuilder('related')
            ->where(\sprintf('related.%s IN (:ids)', $idField))
            ->setParameter('ids', \array_values($ids))
            ->getQuery()
            ->getResult();
        \assert(\is_array($loaded));

        $byId = [];
        foreach ($loaded as $entity) {
            if (!\is_object($entity)) {
                continue;
            }
            $key = $relatedMetadata->getIdentifierValues($entity)[$idField] ?? null;
            if (\is_scalar($key)) {
                $byId[(string) $key] = $entity;
            }
        }

        return $byId;
    }

    /**
     * Groups the bounded native rows by parent (mapping the parent storage id to its
     * wire id through the parent type's id encoder, exactly as the DQL batch does) and
     * builds each parent's {@see CollectionResult}: the (already bounded) entities in row
     * order, with the real per-parent total off the first row (countable) or a count-free
     * `hasMore` from the surplus probe row (non-countable).
     *
     * @param list<array<int|string, mixed>> $rows
     * @param array<string, object>          $relatedById the join-table shape's id-loaded related entities (empty for inverse-FK)
     */
    private function partition(
        array $rows,
        string $parentType,
        \haddowg\JsonApi\Pagination\OffsetWindow $window,
        bool $countable,
        bool $joinTable,
        array $relatedById,
    ): RelatedBatch {
        // Collect each partition as `(rn => entity)` so the items are re-ordered by the
        // ROW_NUMBER even though the OUTER derived-table SELECT carries no ORDER BY (its
        // row order is engine-dependent; the rn IS the within-partition order). Per parent
        // the rn is 1..cap, so it is a stable, gap-free key to ksort on.
        /** @var array<string, array<int, object>> $partitionByRn */
        $partitionByRn = [];
        /** @var array<string, int> $partitionTotal */
        $partitionTotal = [];

        foreach ($rows as $row) {
            // The inverse-FK shape carries the hydrated entity at index 0; the join-table
            // shape carries the related id scalar, re-associated to the id-loaded entity.
            if ($joinTable) {
                $relatedId = $row[BatchScope::RELATED_DISCRIMINATOR_ALIAS] ?? null;
                $related = \is_scalar($relatedId) ? ($relatedById[(string) $relatedId] ?? null) : null;
            } else {
                $related = $row[0] ?? null;
            }
            $storageKey = $row[BatchScope::PARENT_DISCRIMINATOR_ALIAS] ?? null;
            $rn = $row['rn'] ?? null;
            if (!\is_object($related) || !\is_scalar($storageKey) || !\is_numeric($rn)) {
                continue;
            }

            $wireId = $this->wireId($storageKey, $parentType);
            $partitionByRn[$wireId][(int) $rn] = $related;

            if ($countable) {
                $total = $row['total'] ?? null;
                $partitionTotal[$wireId] = \is_numeric($total) ? (int) $total : 0;
            }
        }

        $results = [];
        foreach ($partitionByRn as $wireId => $byRn) {
            \ksort($byRn);
            $items = \array_values($byRn);
            // The native row cap already bounded rows per parent (rn <= offset+limit for
            // a countable, +1 for the count-free probe). For an include offset is 0, so
            // the rows ARE the page; honour a real offset defensively (a non-include
            // caller) by slicing [offset, limit] in PHP.
            $offset = $window->offset;
            $limit = $window->limit;

            if ($countable) {
                $page = \array_slice($items, $offset, $limit);

                $results[$wireId] = new CollectionResult($page, total: $partitionTotal[$wireId] ?? 0, windowed: true);

                continue;
            }

            // Count-free: the cap fetched up to offset+limit+1; the surplus row past the
            // window proves a further page. Slice the window, then hasMore from the probe.
            $sliced = \array_slice($items, $offset, $limit + 1);
            $hasMore = \count($sliced) > $limit;
            if ($hasMore) {
                $sliced = \array_slice($sliced, 0, $limit);
            }

            $results[$wireId] = new CollectionResult($sliced, total: null, windowed: true, hasMore: $hasMore);
        }

        return new RelatedBatch($results);
    }

    /**
     * The resolved sort directives for the relation's windowed set: the requested
     * `?sort` (validated against the declared vocabulary) when present, else the related
     * resource's default sort. Mirrors {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier}'s
     * resolution so the native ORDER BY selects the SAME members as the witness.
     *
     * @return list<SortDirective>
     */
    private function sortDirectives(CollectionCriteria $criteria): array
    {
        $requested = $criteria->queryParameters->sort;
        if ($requested === []) {
            $this->assertFieldSorts($criteria->defaultSort);

            return $criteria->defaultSort;
        }

        // Validate the requested sort against the declared vocabulary EXACTLY as
        // CriteriaApplier::applySorts does, so an unknown/unsupported sort is the
        // endpoint's same 400 on the native path (not a silently-dropped directive):
        // an empty sort vocabulary -> SortingUnsupported, an unknown field ->
        // SortParamUnrecognized.
        if ($criteria->sorts === []) {
            throw new SortingUnsupported();
        }

        $directives = [];
        foreach ($requested as $field) {
            $descending = \str_starts_with($field, '-');
            $key = $descending ? \substr($field, 1) : $field;

            $sort = null;
            foreach ($criteria->sorts as $declared) {
                if ($declared->key() === $key) {
                    $sort = $declared;

                    break;
                }
            }

            if ($sort === null) {
                throw new SortParamUnrecognized($key);
            }

            $directives[] = new SortDirective($sort, $descending);
        }

        $this->assertFieldSorts($directives);

        return $directives;
    }

    /**
     * Asserts every directive sorts by a {@see SortByField} (a column + direction the
     * native window can express); a computed sort cannot be windowed natively.
     *
     * @param list<SortDirective> $directives
     */
    private function assertFieldSorts(array $directives): void
    {
        foreach ($directives as $directive) {
            if (!$directive->sort instanceof SortByField) {
                throw new \LogicException(\sprintf(
                    'The %s can only natively window a SortByField order; got %s. Supply a custom DataProvider or set json_api.doctrine.window_functions: false.',
                    self::class,
                    $directive->sort::class,
                ));
            }
        }
    }

    /**
     * The owning-side field on the related entity for an inverse-side association on the
     * parent (the FK an inverse OneToMany is `mappedBy`), or null when the parent's
     * association is owning-side / many-to-many. Mirrors {@see RelationScope}.
     *
     * @param ClassMetadata<object> $parentMetadata
     */
    private function inverseOwningField(ClassMetadata $parentMetadata, string $property): ?string
    {
        if (!$parentMetadata->hasAssociation($property)) {
            return null;
        }

        $mapping = $parentMetadata->getAssociationMapping($property);
        if ($mapping->isOwningSide()) {
            return null;
        }

        $mappedBy = $mapping['mappedBy'] ?? null;

        return \is_string($mappedBy) ? $mappedBy : null;
    }

    /**
     * The parent storage ids (the `:jsonapi_parent_ids` IN-list), stringified so the array
     * parameter binds uniformly across id types (an int PK binds the same as a string).
     *
     * @param list<object>          $parents
     * @param ClassMetadata<object> $parentMetadata
     *
     * @return list<string>
     */
    private function parentStorageIds(array $parents, ClassMetadata $parentMetadata): array
    {
        $field = $parentMetadata->getSingleIdentifierFieldName();

        $ids = [];
        foreach ($parents as $parent) {
            $key = $parentMetadata->getIdentifierValues($parent)[$field] ?? null;
            if (\is_scalar($key)) {
                $ids[] = (string) $key;
            }
        }

        return $ids;
    }

    /**
     * The parent's wire id for a native row's `jsonapi_parent_id` storage-key scalar —
     * encoded through the parent type's id encoder exactly as
     * {@see DoctrineDataProvider::partitionByParent()} keys the DQL batch, so the two
     * batch shapes key on identical wire ids. An unencoded type stringifies the storage
     * key, the common case.
     */
    private function wireId(int|string|float|bool $storageKey, string $parentType): string
    {
        $encoder = $this->idEncoders->encoderFor($parentType);

        return $encoder !== null ? $encoder->encode($storageKey) : (string) $storageKey;
    }

    /**
     * Executes the native query, wrapping a window-function failure (an engine without
     * ROW_NUMBER on the default `on` setting) in a signposted {@see \RuntimeException} —
     * a normal 500 the kernel.exception listener logs, pointing at the version floors and
     * the off switch. No platform pre-probe (that would be the forbidden auto-detection):
     * the throw surfaces at query time only.
     *
     * @return list<array<int|string, mixed>>
     */
    private function runOrThrow(\Doctrine\ORM\NativeQuery $query): array
    {
        try {
            /** @var list<array<int|string, mixed>> $rows */
            $rows = $query->getResult();

            return $rows;
        } catch (\Doctrine\DBAL\Exception $exception) {
            throw new \RuntimeException(
                'The bounded windowed-include batch requires SQL window functions '
                . '(ROW_NUMBER/COUNT OVER), which this database does not support. They '
                . 'need MySQL >= 8, MariaDB >= 10.2, SQLite >= 3.25, or any PostgreSQL. '
                . 'Set json_api.doctrine.window_functions: false to use the per-parent '
                . 'bounded fallback instead.',
                0,
                $exception,
            );
        }
    }
}
