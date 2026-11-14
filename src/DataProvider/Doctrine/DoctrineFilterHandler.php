<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\ThroughPath;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;
use haddowg\JsonApiBundle\DataProvider\FilterPaths;
use haddowg\JsonApiBundle\DataProvider\ReadsFilterPathsInterface;

/**
 * Executes core's filter value objects against a Doctrine `QueryBuilder`,
 * pushing each predicate down to DQL (`andWhere`, parameter-bound). Semantics
 * mirror core's in-memory `ArrayFilterHandler` — the conformance witness — so
 * the same spec test passes on both providers:
 *
 * - {@see Where} comparison operators map to their DQL equivalents; `like`,
 *   `starts` and `ends` are the three wildcard-`LIKE` string strategies — all
 *   **case-insensitive for ASCII** (the reference contract — the in-memory handler
 *   folds via `stripos`/`str_ends_with`): the value is wildcard-escaped and both
 *   sides are `LOWER()`ed so the behaviour does not depend on the platform's `LIKE`
 *   collation (PostgreSQL's `LIKE` is case-sensitive, SQLite's folds ASCII only).
 *   They differ only in where the `%` wildcard wraps the value — contains `%…%`,
 *   starts `…%`, ends `%…` (the `Contains`/`StartsWith`/`EndsWith` conveniences).
 *   Case-folding beyond ASCII remains platform-defined. `==`/`===` both map to `=`
 *   — DQL has a single, type-coercing equality.
 * - {@see Range} (and {@see \haddowg\JsonApi\Resource\Filter\DateRange}, which
 *   extends it) is the structured `min`/`max` filter: two push-down `andWhere`
 *   predicates (`>= min`, `<= max`) over the present bounds on the SAME primary
 *   query — one query, no join, no subquery, no relation load.
 * - {@see WhereIn}/{@see WhereIdIn} with an empty value list match nothing
 *   (`IN ()` is not valid SQL); the negated variants then match everything.
 * - {@see WhereNull}/{@see WhereNotNull} ignore the request value entirely.
 * - {@see WhereAll}/{@see WhereAny} are server-composed boolean groups: each child
 *   is applied through this same dispatch (fanning the group's value uniformly),
 *   its predicate captured, and the children recombined with `andX()`/`orX()` as
 *   one composite `andWhere` — so a fanning group is a multi-column search and a
 *   fixed-child group a canned toggle, and groups nest (a group child re-enters
 *   here). A `->fixed()` `Where` needs no arm of its own: its pinned value rides
 *   the existing `deserialize` seam, so the {@see Where} arm runs it unchanged.
 * - {@see WhereHas}/{@see WhereDoesntHave}, {@see WhereThrough} and the bundle's
 *   {@see WhereHasMatching} are relationship filters: each matches rows whose
 *   named relationship has at least one related row (optionally narrowed). All
 *   three share ONE correlated `EXISTS` (or `NOT EXISTS`) subquery builder
 *   ({@see existsSubquery()}) — set-membership, not a join into the primary
 *   `SELECT`, so the primary-data rows are neither duplicated nor in need of a
 *   `DISTINCT`, and a to-one and a to-many translate identically; it never
 *   hydrates the relation, so linkage / `?include` / the relationQuery profile
 *   compose for free. The subquery roots on the RELATED entity (the first hop's
 *   target) and correlates back to the outer owner, so an inner predicate hangs
 *   off the related root. The three front-ends differ only in what they ask of
 *   that builder:
 *   - {@see WhereHas}/{@see WhereDoesntHave} — the degenerate length-1 path: no
 *     further hops, no leaf predicate, pure existence (the in-memory handler's
 *     non-empty-collection / non-null witness; negated for `WhereDoesntHave`).
 *   - {@see WhereThrough} — a dotted traversal: the intermediate segments chain
 *     as joins off the related root and the final segment compares as the leaf,
 *     `EXISTS-ANY` (the portable, value-validated default).
 *   - {@see WhereThroughIn} — the same traversal with an `IN` over the requested
 *     list at the leaf, still one `EXISTS` however many values are requested; an
 *     empty list matches nothing. For either traversal, a path resolved to end on a
 *     relationship joins every segment and compares the identifier of the entity
 *     the last hop reached.
 *   - {@see WhereHasMatching} — a single relationship hop whose related root is
 *     narrowed by an author-supplied {@see \Doctrine\Common\Collections\Criteria}
 *     (via `addCriteria`) or a raw-subquery closure (the Doctrine-only escape
 *     hatch; not value-validated, not portable).
 *
 * Columns come from the server-side resource declaration, never the client, and
 * are validated as DQL field paths (dots allowed for embeddables) before being
 * interpolated; values are always bound as query parameters.
 *
 * The handler is also the bundle's {@see AliasAwareFilterHandler}: the shared
 * {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier} can push a declared
 * filter down onto a NON-root alias of the query (the pivot related-collection path
 * applies pivot keys on the joined `pivot` alias, related keys on the root, all on
 * the one builder). `apply()` is `applyOn()` on the query root, so
 * every non-pivot path stays byte-identical.
 *
 * A custom {@see FilterInterface} the built-ins do not recognise is delegated to a
 * registered {@see DoctrineFilterArmInterface} (constructor-injected from the
 * autoconfigured tag, first {@see DoctrineFilterArmInterface::supports()} match wins)
 * before {@see UnsupportedFilter} is raised — the Doctrine half of the framework's
 * extensible-handler seam.
 *
 * @implements FilterHandlerInterface<QueryBuilder>
 * @implements AliasAwareFilterHandler<QueryBuilder>
 */
final class DoctrineFilterHandler implements FilterHandlerInterface, AliasAwareFilterHandler, ReadsFilterPathsInterface
{
    /**
     * Data-layer-specific remediation appended to the core {@see UnsupportedFilter}
     * message when a custom filter reaches this Doctrine handler with no arm to run it —
     * naming the seam that would handle it, so the 500's message points at the fix.
     */
    private const string ARM_HINT = 'To run a custom filter on the Doctrine provider, register a DoctrineFilterArmInterface (tag: json_api.doctrine_filter_arm) — see the "custom filters and sorts: the arm seam" section of the doctrine docs.';

    /**
     * @var list<DoctrineFilterArmInterface>
     */
    private readonly array $arms;

    private readonly NaiveDateTimeStorage $dates;

    private int $subqueries = 0;

    /**
     * The declared relations of the type the filter being applied runs against, from
     * {@see withPaths()}.
     */
    private ?FilterPaths $paths = null;

    /**
     * @param iterable<DoctrineFilterArmInterface>                                   $arms             author arms for custom filter types, consulted in order
     * @param (\Closure(BelongsToMany, class-string): ?PivotAssociation)|null $pivotAssociation the association entity behind a pivot `belongsToMany` declared on an entity class, or null when the relation's related type has no entity
     */
    public function __construct(iterable $arms = [], private readonly ?\Closure $pivotAssociation = null)
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
        $this->dates = new NaiveDateTimeStorage();
    }

    public function withPaths(FilterPaths $paths, \Closure $apply): mixed
    {
        $previous = $this->paths;
        $this->paths = $paths;
        try {
            return $apply();
        } finally {
            $this->paths = $previous;
        }
    }

    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        if (!$query instanceof QueryBuilder) {
            throw new \LogicException(\sprintf(
                'The %s expects a %s query; got %s.',
                self::class,
                QueryBuilder::class,
                \get_debug_type($query),
            ));
        }

        return $this->applyOn($filter, $query, $value, $this->rootAlias($query));
    }

    public function applyOn(FilterInterface $filter, mixed $query, mixed $value, string $alias): mixed
    {
        if (!$query instanceof QueryBuilder) {
            throw new \LogicException(\sprintf(
                'The %s expects a %s query; got %s.',
                self::class,
                QueryBuilder::class,
                \get_debug_type($query),
            ));
        }

        return match (true) {
            // Server-composed boolean groups: recurse each child into a captured
            // predicate and combine with andX()/orX() as one composite andWhere.
            $filter instanceof WhereAll => $this->whereAll($query, $filter, $value, $alias),
            $filter instanceof WhereAny => $this->whereAny($query, $filter, $value, $alias),
            $filter instanceof Where => $this->where($filter, $query, $value, $alias),
            $filter instanceof WhereIn => $this->whereIn($query, $this->pivotColumn($filter->column, $alias), $this->members($filter, $value), false, $alias),
            $filter instanceof WhereNotIn => $this->whereIn($query, $this->pivotColumn($filter->column, $alias), $this->members($filter, $value), true, $alias),
            $filter instanceof WhereIdIn => $this->whereIn($query, $filter->column, $this->members($filter, $value), false, $alias),
            $filter instanceof WhereIdNotIn => $this->whereIn($query, $filter->column, $this->members($filter, $value), true, $alias),
            $filter instanceof WhereNull => $query->andWhere(\sprintf('%s IS NULL', $this->path($this->pivotColumn($filter->column, $alias), $alias))),
            $filter instanceof WhereNotNull => $query->andWhere(\sprintf('%s IS NOT NULL', $this->path($this->pivotColumn($filter->column, $alias), $alias))),
            $filter instanceof WhereThrough => $this->whereThrough($query, $filter, $value, $alias),
            $filter instanceof WhereThroughIn => $this->whereThroughIn($query, $filter, $value, $alias),
            $filter instanceof WhereHas => $this->whereHas($query, $filter->relationship, false, $alias),
            $filter instanceof WhereDoesntHave => $this->whereHas($query, $filter->relationship, true, $alias),
            $filter instanceof WhereHasMatching => $this->whereHasMatching($query, $filter, $value, $alias),
            // Range (and DateRange, which extends it) is a structured min/max filter:
            // two push-down andWhere predicates on the SAME builder, no subquery.
            $filter instanceof Range => $this->range($query, $filter, $value, $alias),
            // A self-applying filter carries its own Doctrine query fragment (a named
            // query-builder method, a Criteria, raw DQL) — consulted before the arm
            // registry, so no separate arm service is needed.
            $filter instanceof AppliesToQueryBuilder => $this->applySelf($filter, $query, $value, $alias),
            default => $this->applyArm($filter, $query, $value, $alias),
        };
    }

    /**
     * Runs a self-applying filter's own Doctrine query fragment, returning the builder so
     * it composes in the match exactly like a built-in. The filter owns its parameter
     * binding (the same discipline an arm follows).
     */
    private function applySelf(AppliesToQueryBuilder $filter, QueryBuilder $query, mixed $value, string $alias): QueryBuilder
    {
        $filter->applyToQueryBuilder($query, $value, $alias);

        return $query;
    }

    /**
     * Delegates a custom {@see FilterInterface} to the first registered
     * {@see DoctrineFilterArmInterface} that {@see DoctrineFilterArmInterface::supports()}
     * it; {@see UnsupportedFilter} when none does (the same signal the built-in
     * default arm gave).
     */
    private function applyArm(FilterInterface $filter, QueryBuilder $query, mixed $value, string $alias): QueryBuilder
    {
        foreach ($this->arms as $arm) {
            if ($arm->supports($filter)) {
                $arm->apply($filter, $query, $value, $alias);

                return $query;
            }
        }

        throw new UnsupportedFilter($filter, self::ARM_HINT);
    }

    /**
     * Relationship-existence predicate ({@see WhereHas}/{@see WhereDoesntHave}):
     * the degenerate front-end of {@see existsSubquery()} — a length-1 path (the
     * relationship is the leaf hop) with NO leaf predicate, so a row matches iff
     * it has at least one related row (negated for `WhereDoesntHave`).
     */
    private function whereHas(QueryBuilder $query, string $relationship, bool $negate, string $alias): QueryBuilder
    {
        $exists = $query->expr()->exists(
            $this->existsSubquery($query, [$relationship], null, $alias)->getDQL(),
        );

        return $query->andWhere($negate ? $query->expr()->not($exists) : $exists);
    }

    /**
     * Dotted-path traversal predicate ({@see WhereThrough}): an `EXISTS-ANY`
     * semi-join — chain the path's intermediate relationship segments as joins
     * inside one subquery and compare the final attribute segment as the leaf,
     * with the same operator vocabulary / `like` semantics as {@see where()}
     * (the shared {@see applyComparison()}). The leaf parameter binds on the
     * OUTER `$query` (which executes), the predicate is added on the leaf alias.
     * A path resolved to a relationship leaf compares the related entity's id with
     * the decoded storage key, bound through the id's Doctrine type.
     */
    private function whereThrough(QueryBuilder $query, WhereThrough $filter, mixed $value, string $alias): QueryBuilder
    {
        $expected = $filter->storageValue($value);
        $onRelation = $this->endsOnRelation($filter->resolved);
        $segments = $this->segments($filter->key(), $filter->path, $onRelation);

        $exists = $query->expr()->exists(
            $this->existsSubquery(
                $query,
                $segments,
                function (QueryBuilder $sub, string $leafAlias, string $leafField, ClassMetadata $leafMetadata) use ($query, $filter, $expected, $onRelation): void {
                    // The predicate TEXT goes on the subquery (via its leaf alias);
                    // the placeholder name and the bound value go on the OUTER $query,
                    // which executes the embedded EXISTS — so a placeholder collision
                    // across several traversal filters on one query cannot happen.
                    if (!$onRelation && !$leafMetadata->hasAssociation($leafField)) {
                        $this->applyComparison($query, $sub, $leafAlias . '.' . $leafField, $filter->operator, $expected, $filter->key());

                        return;
                    }

                    // A relationship leaf compares the related entity's id, and an
                    // association leaf the id of the entity it holds.
                    [$path, $metadata] = $onRelation
                        ? [DoctrineIdentifiers::path($leafMetadata, $leafAlias), $leafMetadata]
                        : ['IDENTITY(' . $leafAlias . '.' . $leafField . ')', $query->getEntityManager()->getClassMetadata($leafMetadata->getAssociationTargetClass($leafField))];
                    $placeholder = $this->placeholder($query);
                    $sub->andWhere(\sprintf('%s %s :%s', $path, $this->idOperator($filter), $placeholder));
                    (new DoctrineIdentifiers($query->getEntityManager()))->bind($query, $placeholder, $metadata, $expected);
                },
                $alias,
                $onRelation,
            )->getDQL(),
        );

        return $query->andWhere($exists);
    }

    /**
     * Traversal set predicate ({@see WhereThroughIn}): {@see whereThrough()}'s one
     * correlated `EXISTS` with an `IN` over the requested list at the leaf, so the
     * query carries one subquery however many values are requested. A relationship
     * leaf compares the related entity's id with the decoded storage keys, bound
     * through the id's Doctrine type. An empty list matches nothing, with no `IN ()`.
     */
    private function whereThroughIn(QueryBuilder $query, WhereThroughIn $filter, mixed $value, string $alias): QueryBuilder
    {
        $values = $filter->storageValues($value);
        if ($values === []) {
            return $query->andWhere('1 = 0');
        }

        $onRelation = $this->endsOnRelation($filter->resolved);
        $segments = $this->segments($filter->key(), $filter->path, $onRelation);

        $exists = $query->expr()->exists(
            $this->existsSubquery(
                $query,
                $segments,
                function (QueryBuilder $sub, string $leafAlias, string $leafField, ClassMetadata $leafMetadata) use ($query, $values, $onRelation): void {
                    $placeholder = $this->placeholder($query);
                    if (!$onRelation && !$leafMetadata->hasAssociation($leafField)) {
                        $sub->andWhere(\sprintf('%s.%s IN (:%s)', $leafAlias, $leafField, $placeholder));
                        $query->setParameter($placeholder, ...$this->listParameter($query, $leafMetadata, $leafField, $values));

                        return;
                    }

                    [$path, $metadata] = $onRelation
                        ? [DoctrineIdentifiers::path($leafMetadata, $leafAlias), $leafMetadata]
                        : ['IDENTITY(' . $leafAlias . '.' . $leafField . ')', $query->getEntityManager()->getClassMetadata($leafMetadata->getAssociationTargetClass($leafField))];
                    $sub->andWhere(\sprintf('%s IN (:%s)', $path, $placeholder));
                    (new DoctrineIdentifiers($query->getEntityManager()))->bindList($query, $placeholder, $metadata, $values);
                },
                $alias,
                $onRelation,
            )->getDQL(),
        );

        return $query->andWhere($exists);
    }

    /**
     * @phpstan-assert-if-true !null $path
     */
    private function endsOnRelation(?ThroughPath $path): bool
    {
        return $path !== null && $path->endsOnRelation();
    }

    /**
     * The DQL operator a traversal comparing an id applies: inequality for a negated
     * operator, equality for any other.
     */
    private function idOperator(WhereThrough $filter): string
    {
        return \in_array($filter->operator, ['!=', '<>'], true) ? '<>' : '=';
    }

    /**
     * A traversal path's segments. A path ending on a field needs a relationship and
     * the field it compares; one resolved to a relationship leaf may be that single
     * relationship.
     *
     * @return non-empty-list<string>
     */
    private function segments(string $key, string $path, bool $onRelation): array
    {
        $segments = ThroughPath::split($path);
        if (!$onRelation && \count($segments) < 2) {
            throw new \LogicException(\sprintf(
                'Traversal filter "%s" needs a dotted path "relationship.attribute", or a path that ends on a relationship; got "%s".',
                $key,
                $path,
            ));
        }

        return $segments;
    }

    /**
     * Author-supplied relationship-existence predicate ({@see WhereHasMatching}):
     * a single relationship hop whose related root is narrowed by a {@see \Doctrine\Common\Collections\Criteria}
     * (applied with `addCriteria` on the related root) or a raw-subquery closure.
     * Feeds the SAME {@see existsSubquery()} builder; the related entity stays the
     * subquery root so both narrowing surfaces hang off it naturally.
     */
    private function whereHasMatching(QueryBuilder $query, WhereHasMatching $filter, mixed $value, string $alias): QueryBuilder
    {
        $criteria = $filter->criteria;
        $build = $filter->build;

        $exists = $query->expr()->exists(
            $this->existsSubquery(
                $query,
                [$filter->relationship],
                static function (QueryBuilder $sub, string $relatedAlias) use ($query, $criteria, $build, $value): void {
                    // The leaf segment IS the related root for a single hop, so
                    // the author narrows the related root directly.
                    if ($criteria !== null) {
                        $sub->addCriteria($criteria);
                    }

                    if ($build !== null) {
                        $build($sub, $relatedAlias, $value);
                    }

                    // Bound parameters (addCriteria's auto-named bindings or the
                    // closure's own) live on the subquery; lift them onto the
                    // OUTER $query, which executes the embedded EXISTS (only its DQL
                    // string is taken from $sub, so $sub needs no parameters itself).
                    foreach ($sub->getParameters() as $parameter) {
                        $query->getParameters()->add($parameter);
                    }
                },
                $alias,
            )->getDQL(),
        );

        return $query->andWhere($exists);
    }

    /**
     * The ONE correlated `EXISTS` subquery builder behind every relationship
     * filter. `$segments` is the dotted path: the first segment is the
     * relationship off the entity `$alias` names on the outer query (its TARGET
     * becomes the subquery root — the RELATED entity, so an author predicate hangs
     * off it), each subsequent
     * intermediate segment is a further relationship joined in turn, and the LAST
     * segment is the leaf attribute. A length-1 path is the degenerate case
     * (relationship only, no further hops, no leaf attribute).
     *
     * The subquery roots on the related entity and correlates back to the outer
     * owner, the entity at `$alias`, by a membership `IN`-subquery on the owning
     * association. That is the outer root on a collection read and the joined
     * related entity on a relation count or window, so the filter tests the counted
     * member rather than its parent. The membership subquery is uniform for
     * to-one and to-many, owning-side and many-to-many, and never needs the
     * inverse field, so the related root is the builder's first alias and
     * `addCriteria` / the traversal joins both target it.
     *
     * `$applyLeaf` (null for pure existence) receives `(subquery, leafAlias, leafField,
     * leafMetadata)` to add the leaf predicate: `leafAlias` is the alias the final hop
     * reached, `leafField` the leaf attribute name (empty for a length-1 path or a
     * relationship leaf) and `leafMetadata` the entity `leafAlias` names.
     *
     * With `$leafIsRelation` every segment is a relationship, the last one included, so
     * each is joined and `leafAlias` is the related entity whose id the leaf compares.
     *
     * @param list<string>                                                      $segments  the dotted path's segments (≥ 1)
     * @param \Closure(QueryBuilder, string, string, ClassMetadata<object>): void|null $applyLeaf
     */
    private function existsSubquery(QueryBuilder $query, array $segments, ?\Closure $applyLeaf, string $alias, bool $leafIsRelation = false): QueryBuilder
    {
        $ownerMetadata = $this->metadataOfAlias($query, $alias)
            ?? throw new \LogicException(\sprintf('The QueryBuilder has no entity at alias "%s" to filter on.', $alias));
        $ownerEntity = $ownerMetadata->getName();

        $entityManager = $query->getEntityManager();
        $relations = $this->paths?->relationsAlong(\implode('.', $segments)) ?? [];

        // The first segment is the owning relationship; resolve its target as the
        // RELATED root and split off any remaining intermediate relationships and
        // the leaf attribute. A pivot belongsToMany reaches its target through its
        // association entity.
        $pivot = $this->pivotHop($ownerMetadata, $segments[0], $relations[0] ?? null);
        if ($pivot !== null) {
            $firstSegment = $segments[0];
            $relatedClass = $entityManager->getClassMetadata($pivot->entityClass)->getAssociationTargetClass($pivot->farProperty);
        } else {
            $firstSegment = $this->assertAssociation($ownerMetadata, $segments[0]);
            $relatedClass = $ownerMetadata->getAssociationTargetClass($firstSegment);
        }

        $subQuery = $entityManager->createQueryBuilder();

        // A per-subquery alias stem so several relationship filters on one query keep
        // distinct subquery scopes. A counter rather than the builder's object id: a
        // group builds its children's subqueries one after another, and a freed
        // builder's id is reused.
        $stem = 'jsonapi_has_' . ++$this->subqueries;
        $relatedAlias = $stem . '_rel';

        $subQuery
            ->select('1')
            ->from($relatedClass, $relatedAlias);

        // Correlate the related root back to the outer owner via a membership
        // IN-subquery on the owning association (mirrors RelationScope's subquery
        // branch): the inner-inner subquery roots on the owner, joins the first hop,
        // selects the related ids, and is correlated to the outer root by alias.
        $relatedMetadata = $entityManager->getClassMetadata($relatedClass);
        if ($pivot !== null) {
            // A pivot's members are the far ids of the owner's association rows.
            $rowAlias = $stem . '_row';
            $membership = $entityManager->createQueryBuilder()
                ->select(\sprintf('IDENTITY(%s.%s)', $rowAlias, $pivot->farProperty))
                ->from($pivot->entityClass, $rowAlias)
                ->where(DoctrineIdentifiers::joins($rowAlias . '.' . $pivot->parentProperty, $ownerMetadata, $alias));
        } else {
            $ownerAlias = $stem . '_owner';
            $membershipAlias = $stem . '_member';
            $membership = $entityManager->createQueryBuilder()
                ->select(DoctrineIdentifiers::path($relatedMetadata, $membershipAlias))
                ->from($ownerEntity, $ownerAlias)
                ->innerJoin(\sprintf('%s.%s', $ownerAlias, $firstSegment), $membershipAlias)
                ->where(\sprintf('%s = %s', DoctrineIdentifiers::entity($ownerMetadata, $ownerAlias), DoctrineIdentifiers::entity($ownerMetadata, $alias)));
        }
        $subQuery->andWhere($subQuery->expr()->in(
            DoctrineIdentifiers::path($relatedMetadata, $relatedAlias),
            $membership->getDQL(),
        ));

        // Chain the remaining intermediate relationship segments off the related
        // root, resolving each as an association at build time; the final segment
        // is the leaf attribute (validated as a field, not joined).
        $currentAlias = $relatedAlias;
        $currentMetadata = $entityManager->getClassMetadata($relatedClass);
        $leafField = '';

        $intermediate = $leafIsRelation ? \array_slice($segments, 1) : \array_slice($segments, 1, -1);
        foreach ($intermediate as $offset => $segment) {
            $nextAlias = $currentAlias . '_' . $segment;
            $pivot = $this->pivotHop($currentMetadata, $segment, $relations[$offset + 1] ?? null);
            if ($pivot !== null) {
                $rowAlias = $nextAlias . '_row';
                $subQuery
                    ->innerJoin($pivot->entityClass, $rowAlias, 'WITH', DoctrineIdentifiers::joins($rowAlias . '.' . $pivot->parentProperty, $currentMetadata, $currentAlias))
                    ->innerJoin(\sprintf('%s.%s', $rowAlias, $pivot->farProperty), $nextAlias);
                $currentMetadata = $entityManager->getClassMetadata($entityManager->getClassMetadata($pivot->entityClass)->getAssociationTargetClass($pivot->farProperty));
            } else {
                $association = $this->assertAssociation($currentMetadata, $segment);
                $subQuery->innerJoin(\sprintf('%s.%s', $currentAlias, $association), $nextAlias);
                $currentMetadata = $entityManager->getClassMetadata($currentMetadata->getAssociationTargetClass($association));
            }
            $currentAlias = $nextAlias;
        }

        if (!$leafIsRelation && \count($segments) > 1) {
            $leafField = $this->assertLeaf($currentMetadata, $segments[\count($segments) - 1]);
        }

        if ($applyLeaf !== null) {
            $applyLeaf($subQuery, $currentAlias, $leafField, $currentMetadata);
        }

        return $subQuery;
    }

    /**
     * Asserts `$segment` names a Doctrine association on `$metadata` and returns it,
     * validating the identifier first so a declaration typo fails loudly rather than
     * reaching the DQL parser interpolated.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function assertAssociation(ClassMetadata $metadata, string $segment): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine association name.', $segment));
        }

        if (!$metadata->hasAssociation($segment)) {
            throw new \LogicException(\sprintf(
                '"%s" is not an association on "%s"; a traversal path\'s intermediate segments must be relationships.',
                $segment,
                $metadata->getName(),
            ));
        }

        return $segment;
    }

    /**
     * Asserts `$segment` names what a traversal path may end on, a Doctrine field or a
     * single-valued association that holds its foreign key, on `$metadata` and returns
     * it, validating the identifier first. An association leaf compares the id of the
     * entity it holds.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function assertLeaf(ClassMetadata $metadata, string $segment): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine field name.', $segment));
        }

        if ($metadata->hasField($segment)) {
            return $segment;
        }

        if ($metadata->hasAssociation($segment) && $metadata->isSingleValuedAssociation($segment) && !$metadata->isAssociationInverseSide($segment)) {
            return $segment;
        }

        throw new \LogicException(\sprintf(
            '"%s" is not a field or a foreign-key association on "%s"; a traversal path\'s final segment must be an attribute, or an association whose id it compares.',
            $segment,
            $metadata->getName(),
        ));
    }

    /**
     * The association entity behind `$segment` on `$metadata` when it names a pivot
     * `belongsToMany` rather than a Doctrine association, from the declared `$relation`
     * the path walks there; null for an association, or a segment with no such relation.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function pivotHop(ClassMetadata $metadata, string $segment, ?RelationInterface $relation): ?PivotAssociation
    {
        if ($metadata->hasAssociation($segment) || $this->pivotAssociation === null || !$relation instanceof BelongsToMany || $relation->storedPivotFields() === []) {
            return null;
        }

        /** @var class-string $class */
        $class = $metadata->getName();

        return ($this->pivotAssociation)($relation, $class);
    }

    private function where(Where $filter, QueryBuilder $query, mixed $value, string $alias): QueryBuilder
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;
        $path = $this->path($this->pivotColumn($filter->column, $alias), $alias);

        $this->applyComparison($query, $query, $path, $filter->operator, $expected, $filter->key());

        return $query;
    }

    /**
     * AND group ({@see WhereAll}): each child's predicate is combined with `AND`
     * under one composite `andWhere`. Every child re-enters {@see applyOn()} with
     * the **group's** value (fanned uniformly) and the SAME `$alias`, so a fixed
     * child ignores the value, a fanning child consumes it, and a nested group
     * re-enters this dispatch. An empty group adds no constraint (the AND identity).
     */
    private function whereAll(QueryBuilder $query, WhereAll $filter, mixed $value, string $alias): QueryBuilder
    {
        $parts = $this->childPredicates($query, $filter->children, $value, $alias);

        if ($parts === []) {
            return $query;
        }

        return $query->andWhere($query->expr()->andX(...$parts));
    }

    /**
     * OR group ({@see WhereAny}): each child's predicate is combined with `OR`
     * under one composite `andWhere` — so a value-carrying group is a multi-column
     * search (`filter[q]=foo` → `col1 LIKE foo OR col2 LIKE foo`) and a fixed-child
     * group a canned toggle. An empty group matches nothing (the OR identity),
     * mirroring the in-memory witness.
     */
    private function whereAny(QueryBuilder $query, WhereAny $filter, mixed $value, string $alias): QueryBuilder
    {
        $parts = $this->childPredicates($query, $filter->children, $value, $alias);

        if ($parts === []) {
            return $query->andWhere('1 = 0');
        }

        return $query->andWhere($query->expr()->orX(...$parts));
    }

    /**
     * Builds one predicate expression per child WITHOUT leaving it AND-ed onto the
     * query's WHERE, so the group can recombine the children with its own boolean
     * operator. Each child is applied through the SAME {@see applyOn()} dispatch a
     * top-level filter uses — so its DQL, its `like` semantics and its parameter
     * binding are byte-identical to a standalone application — then the WHERE parts
     * it added are captured and the WHERE is restored to its pre-child state. The
     * bound parameters are LEFT on the query (their count-derived placeholder names
     * stay unique across every child and any nested group), and the captured
     * expression strings reference them, so the recombined composite executes
     * correctly.
     *
     * @param list<FilterInterface> $children
     * @return list<string> the child predicate DQL expressions
     */
    private function childPredicates(QueryBuilder $query, array $children, mixed $value, string $alias): array
    {
        $parts = [];
        foreach ($children as $child) {
            $before = $this->whereParts($query);
            $this->applyOn($child, $query, $value, $alias);
            $after = $this->whereParts($query);

            $added = \array_slice($after, \count($before));

            // Restore the WHERE to exactly the parts present before this child (the
            // just-added parts are recombined by the group, not left AND-ed on).
            $query->resetDQLPart('where');
            if ($before !== []) {
                $query->andWhere(...$before);
            }

            $parts[] = match (\count($added)) {
                // A child that added no predicate (e.g. NOT IN () ) is a no-op → TRUE.
                0 => '1 = 1',
                1 => $added[0],
                // Several andWhere parts (e.g. a Range's two bounds) AND together as
                // one parenthesised predicate.
                default => '(' . \implode(' AND ', $added) . ')',
            };
        }

        return $parts;
    }

    /**
     * The current top-level WHERE expression as a flat list of its conjuncts' DQL
     * strings: the parts of the `Andx` a chain of `andWhere` builds, a single
     * non-`Andx` expression as a one-element list, or empty when there is no WHERE
     * yet. Each part is a DQL string or a `\Stringable` `Expr\*` node, normalised
     * to its DQL string so it recombines as a plain predicate.
     *
     * @return list<string>
     */
    private function whereParts(QueryBuilder $query): array
    {
        $where = $query->getDQLPart('where');

        if ($where === null) {
            return [];
        }

        $parts = $where instanceof Andx ? \array_values($where->getParts()) : [$where];

        return \array_map(
            static fn(mixed $part): string => $part instanceof \Stringable ? (string) $part : (\is_string($part) ? $part : ''),
            $parts,
        );
    }

    /**
     * Adds one comparison predicate (`$path <op> :param`, or the contains-`LIKE`
     * for `like`) — the predicate TEXT on `$predicateOn`, the placeholder name and
     * the bound value on `$bindOn`. Shared by the {@see Where} column filter (where
     * both are the same query) and the {@see WhereThrough} leaf (predicate on the
     * subquery, parameter on the OUTER query, which executes the embedded `EXISTS`),
     * so the two are byte-identical (same operator vocabulary, same `like` =
     * case-insensitive-contains, same binding) — only the resolved `$path` and the
     * split target differ. `$key` names the filter for the unknown-operator
     * diagnostic.
     */
    private function applyComparison(QueryBuilder $bindOn, QueryBuilder $predicateOn, string $path, string $operator, mixed $expected, string $key): void
    {
        // The three wildcard-LIKE operators differ only in where the `%` wildcard
        // wraps the (escaped) value — contains `%v%`, starts `v%`, ends `%v` —
        // mirroring the in-memory handler's stripos !== false / === 0 / str_ends_with.
        if ($operator === 'like') {
            $this->likeMatch($bindOn, $predicateOn, $path, $expected, '%', '%');

            return;
        }
        if ($operator === 'starts') {
            $this->likeMatch($bindOn, $predicateOn, $path, $expected, '', '%');

            return;
        }
        if ($operator === 'ends') {
            $this->likeMatch($bindOn, $predicateOn, $path, $expected, '%', '');

            return;
        }

        $dqlOperator = match ($operator) {
            '=', '==', '===' => '=',
            '!=', '<>' => '<>',
            '>', '>=', '<', '<=' => $operator,
            default => throw new \LogicException(\sprintf(
                'Filter "%s" declares operator "%s", which has no DQL equivalent.',
                $key,
                $operator,
            )),
        };

        $placeholder = $this->placeholder($bindOn);

        $predicateOn->andWhere(\sprintf('%s %s :%s', $path, $dqlOperator, $placeholder));
        $bindOn->setParameter($placeholder, ...$this->parameter($predicateOn, $path, $expected));
    }

    /**
     * Wildcard-`LIKE` match, mirroring the in-memory handler's `stripos` family:
     * the value's `%`/`_` are literal (escaped with `!`), matching folds ASCII case
     * on both sides (`LOWER()` in DQL + `strtolower` on the bound value, so the
     * result does not depend on the platform's `LIKE` collation), and a non-string
     * value matches nothing — the in-memory comparison requires two strings. The
     * `$prefix`/`$suffix` wrap the escaped value with the SQL `%` wildcard for the
     * three string strategies: contains (`%v%`, the `like` operator → in-memory
     * `stripos !== false`), starts-with (`v%`, `starts` → `stripos === 0`), and
     * ends-with (`%v`, `ends` → `str_ends_with`).
     */
    private function likeMatch(QueryBuilder $bindOn, QueryBuilder $predicateOn, string $path, mixed $expected, string $prefix, string $suffix): void
    {
        if (!\is_string($expected)) {
            $predicateOn->andWhere('1 = 0');

            return;
        }

        $placeholder = $this->placeholder($bindOn);
        $escaped = \str_replace(['!', '%', '_'], ['!!', '!%', '!_'], \strtolower($expected));

        $predicateOn->andWhere(\sprintf("LOWER(%s) LIKE :%s ESCAPE '!'", $path, $placeholder));
        $bindOn->setParameter($placeholder, $prefix . $escaped . $suffix);
    }

    /**
     * Inclusive range predicate ({@see Range}, and {@see \haddowg\JsonApi\Resource\Filter\DateRange}
     * which extends it): the structured value carries an optional `min` and/or `max`
     * bound (the nested `filter[<key>][min]`/`[max]` query Symfony parses into an
     * array). Each present, non-blank bound is coerced through the filter's
     * deserializer (numeric for `Range`, ISO-8601 → `\DateTimeImmutable` for
     * `DateRange`) and bound as a parameter on a `>=`/`<=` predicate added to the
     * SAME primary query — so an open-ended range works (`min` alone is a `>=`,
     * `max` alone a `<=`, neither a no-op) and the whole filter is ONE query with no
     * join, no subquery and no relation load, mirroring the in-memory
     * `ArrayFilterHandler::range()`. A blank/absent bound is treated as absent,
     * byte-for-byte with the in-memory `bound()`, so `filter[<key>][max]=` is a no-op.
     *
     * A {@see DateRange} bound that is shape-valid ISO-8601 but calendar-invalid
     * (`1997-13-99` — passes the lenient shape `Pattern` but not `\DateTimeImmutable`)
     * does not coerce to a `\DateTimeInterface`; the {@see \haddowg\JsonApiBundle\Validation\FilterValueValidator}
     * rejects it as a clean `400` pre-provider, but when that validation is absent
     * this handler **skips** the bound rather than binding a raw non-date string as a
     * datetime parameter — which would compare lexically on a loose driver (SQLite)
     * and raise a driver error (a `500`) on a strict one (PostgreSQL `timestamp`),
     * each diverging from the in-memory witness. So both providers degrade identically.
     */
    private function range(QueryBuilder $query, Range $filter, mixed $value, string $alias): QueryBuilder
    {
        $path = $this->path($this->pivotColumn($filter->column, $alias), $alias);
        $bounds = \is_array($value) ? $value : [];
        $min = $this->bound($filter, $bounds, 'min');
        $max = $this->bound($filter, $bounds, 'max');

        if ($min !== null) {
            $placeholder = $this->placeholder($query);
            $query->andWhere(\sprintf('%s >= :%s', $path, $placeholder))->setParameter($placeholder, ...$this->parameter($query, $path, $min));
        }

        if ($max !== null) {
            $placeholder = $this->placeholder($query);
            $query->andWhere(\sprintf('%s <= :%s', $path, $placeholder))->setParameter($placeholder, ...$this->parameter($query, $path, $max));
        }

        return $query;
    }

    /**
     * Extracts and coerces one range bound: the deserialized bound value, or `null`
     * when the bound is absent or blank (`''`) — so an open-ended range works and a
     * blank bound is a no-op, byte-for-byte with the in-memory
     * {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler::bound()}.
     *
     * For a {@see DateRange} a coercion that did not yield a `\DateTimeInterface`
     * (a shape-valid but unparseable ISO-8601 string such as `1997-13-99`) is also
     * treated as absent, so a non-date string is never bound as a datetime
     * parameter — see {@see range()}.
     *
     * @param array<array-key, mixed> $bounds
     */
    private function bound(Range $filter, array $bounds, string $key): mixed
    {
        if (!\array_key_exists($key, $bounds)) {
            return null;
        }

        /** @var mixed $value */
        $value = $bounds[$key];
        if ($value === null || $value === '') {
            return null;
        }

        $value = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;

        if ($filter instanceof DateRange && !$value instanceof \DateTimeInterface) {
            return null;
        }

        return $value;
    }

    /**
     * @param list<mixed> $values
     */
    private function whereIn(QueryBuilder $query, string $column, array $values, bool $negate, string $alias): QueryBuilder
    {
        $path = $this->path($column, $alias);

        if ($values === []) {
            // in_array(x, []) is always false: IN () matches nothing, NOT IN ()
            // matches everything (a no-op).
            return $negate ? $query : $query->andWhere('1 = 0');
        }

        $placeholder = $this->placeholder($query);
        [$alias, $field] = \explode('.', $path, 2);

        return $query
            ->andWhere(\sprintf('%s %s (:%s)', $path, $negate ? 'NOT IN' : 'IN', $placeholder))
            ->setParameter($placeholder, ...$this->listParameter($query, $this->metadataOfAlias($query, $alias), $field, $values));
    }

    /**
     * The members a set filter queries for: a declared list reads its value through
     * core's `ListValue::members()`, so an array-only list keeps a delimiter inside a
     * member; any other set filter splits leniently through {@see toList()}.
     *
     * @return list<mixed>
     */
    private function members(WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter, mixed $value): array
    {
        return $filter->list?->members($value) ?? $this->toList($value, $filter->delimiter);
    }

    /**
     * Splits the request value the same way the in-memory handler does: arrays
     * pass through, strings split on the filter's delimiter (default `,`) with
     * each element trimmed, anything else becomes a single-element list.
     *
     * @return list<mixed>
     */
    private function toList(mixed $value, ?string $delimiter): array
    {
        if (\is_array($value)) {
            return \array_values($value);
        }

        if (\is_string($value)) {
            $separator = $delimiter !== null && $delimiter !== '' ? $delimiter : ',';

            return \array_values(\array_map('\trim', \explode($separator, $value)));
        }

        return [$value];
    }

    /**
     * Strips a single leading `pivot.` from a declared column WHEN AND ONLY WHEN the
     * filter is applying on the `pivot` join alias — the author-declared pivot-filter
     * convention: a filter whose column is `pivot.position` is routed
     * (by the {@see \haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory} aliasOf
     * map) to the `pivot` alias, where the real association-entity column is `position`.
     * Without this strip {@see path()} would build `pivot.pivot.position`. Only the
     * leading `pivot.` segment is removed, so an embeddable pivot column
     * (`pivot.meta.x`) yields `meta.x`. On every other alias (the root, the count's
     * `related` join) the column passes through unchanged, so each non-pivot path stays
     * byte-identical.
     */
    private function pivotColumn(string $column, string $alias): string
    {
        return $alias === 'pivot' && \str_starts_with($column, 'pivot.')
            ? \substr($column, \strlen('pivot.'))
            : $column;
    }

    /**
     * The DQL path for a declared column on `$alias` (the query root by default,
     * the join alias on the pivot path), validated as an identifier path (dots
     * allowed for embedded fields) so a declaration typo fails loudly rather than
     * reaching the DQL parser interpolated.
     */
    private function path(string $column, string $alias): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine field path.', $column));
        }

        return $alias . '.' . $column;
    }

    /**
     * The value and DBAL type to bind `$value` with when it is compared against the
     * column at `$path` (`alias.field`), read from the metadata of the entity `alias`
     * names on `$query`: a date-time from {@see NaiveDateTimeStorage::parameter()}, a
     * value of a binary-bound column (a `uuid` on a platform without a native UUID
     * type) in its database form, and an association's value as the id of the entity
     * it holds. Anything else, or a value whose column cannot be resolved, binds
     * unchanged.
     *
     * @return array{mixed, \Doctrine\DBAL\ArrayParameterType|ParameterType|int|string|null}
     */
    private function parameter(QueryBuilder $query, string $path, mixed $value): array
    {
        [$alias, $field] = \explode('.', $path, 2);
        $metadata = $this->metadataOfAlias($query, $alias);
        if ($metadata === null) {
            return [$value, null];
        }

        if ($value instanceof \DateTimeInterface) {
            return $metadata->hasField($field) ? $this->dates->parameter($value, $metadata->getTypeOfField($field)) : [$value, null];
        }

        $target = $this->associationTarget($query, $metadata, $field);
        if ($target !== null) {
            $ids = new DoctrineIdentifiers($query->getEntityManager());
            $type = $ids->type($target);

            return [$ids->toDatabase($target, $value), $type?->getBindingType()];
        }

        $type = $this->binaryType($metadata, $field);
        if ($type === null) {
            return [$value, null];
        }

        try {
            return [$type->convertToDatabaseValue($value, $query->getEntityManager()->getConnection()->getDatabasePlatform()), $type->getBindingType()];
        } catch (ConversionException|\InvalidArgumentException|\TypeError|\ValueError) {
            return [$value, null];
        }
    }

    /**
     * The values and DBAL array type to bind a list compared against `$field` of
     * `$metadata` with, each converted as {@see parameter()} converts one. A value the
     * column's type cannot convert is dropped, since it can match no row.
     *
     * @param ClassMetadata<object>|null $metadata
     * @param list<mixed>                $values
     *
     * @return array{mixed, \Doctrine\DBAL\ArrayParameterType|ParameterType|int|string|null}
     */
    private function listParameter(QueryBuilder $query, ?ClassMetadata $metadata, string $field, array $values): array
    {
        if ($metadata === null) {
            return [$values, null];
        }

        $ids = new DoctrineIdentifiers($query->getEntityManager());
        $target = $this->associationTarget($query, $metadata, $field);
        $type = $target !== null ? $ids->type($target) : $this->binaryType($metadata, $field);
        if ($type === null) {
            return [$values, null];
        }

        $platform = $query->getEntityManager()->getConnection()->getDatabasePlatform();
        $converted = [];
        foreach ($values as $value) {
            if ($target !== null) {
                $database = $ids->toDatabase($target, $value);
            } else {
                try {
                    $database = $type->convertToDatabaseValue($value, $platform);
                } catch (ConversionException) {
                    $database = null;
                }
            }
            if ($database !== null) {
                $converted[] = $database;
            }
        }

        /** @var \Doctrine\DBAL\ArrayParameterType|int $listType */
        $listType = DoctrineIdentifiers::listType($type);

        return [$converted, $listType];
    }

    /**
     * The metadata of the entity a single-valued association `$field` holds, or null
     * when `$field` is not one.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return ClassMetadata<object>|null
     */
    private function associationTarget(QueryBuilder $query, ClassMetadata $metadata, string $field): ?ClassMetadata
    {
        return $metadata->hasAssociation($field) && $metadata->isSingleValuedAssociation($field)
            ? $query->getEntityManager()->getClassMetadata($metadata->getAssociationTargetClass($field))
            : null;
    }

    /**
     * The Doctrine type of `$field` when a value compared with it must be converted to
     * its database form first: a Symfony UID type, which stores a UUID or ULID as bytes
     * on a platform with no native UUID type, or any type the driver binds as binary.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function binaryType(ClassMetadata $metadata, string $field): ?Type
    {
        $name = $metadata->hasField($field) ? $metadata->getTypeOfField($field) : null;
        if ($name === null || !Type::hasType($name)) {
            return null;
        }

        $type = Type::getType($name);

        return $type instanceof \Symfony\Bridge\Doctrine\Types\AbstractUidType || \in_array($type->getBindingType(), [ParameterType::BINARY, ParameterType::LARGE_OBJECT], true) ? $type : null;
    }

    /**
     * The metadata of the entity `$alias` names on `$query`: a `FROM` root, an entity
     * joined by class, or the target of an association joined off another alias.
     *
     * @return ClassMetadata<object>|null
     */
    private function metadataOfAlias(QueryBuilder $query, string $alias): ?ClassMetadata
    {
        $entityManager = $query->getEntityManager();

        /** @var list<\Doctrine\ORM\Query\Expr\From> $froms */
        $froms = $query->getDQLPart('from');
        foreach ($froms as $from) {
            if ($from->getAlias() === $alias) {
                return $entityManager->getClassMetadata($from->getFrom());
            }
        }

        /** @var array<string, list<\Doctrine\ORM\Query\Expr\Join>> $joinsByRoot */
        $joinsByRoot = $query->getDQLPart('join');
        foreach ($joinsByRoot as $joins) {
            foreach ($joins as $join) {
                if ($join->getAlias() !== $alias) {
                    continue;
                }

                $target = $join->getJoin();
                if (!\str_contains($target, '.')) {
                    return \class_exists($target) ? $entityManager->getClassMetadata($target) : null;
                }

                [$parentAlias, $association] = \explode('.', $target, 2);
                $parent = $parentAlias === $alias ? null : $this->metadataOfAlias($query, $parentAlias);

                return $parent !== null && $parent->hasAssociation($association)
                    ? $entityManager->getClassMetadata($parent->getAssociationTargetClass($association))
                    : null;
            }
        }

        return null;
    }

    private function rootAlias(QueryBuilder $query): string
    {
        return $query->getRootAliases()[0]
            ?? throw new \LogicException('The QueryBuilder has no root alias to filter on.');
    }

    /**
     * A collision-free parameter placeholder: one filter may bind several
     * parameters across a query, so derive the name from the running count.
     */
    private function placeholder(QueryBuilder $query): string
    {
        return 'jsonapi_filter_' . \count($query->getParameters());
    }
}
