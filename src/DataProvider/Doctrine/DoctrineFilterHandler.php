<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;

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
 * the one builder — bundle ADR 0059). `apply()` is `applyOn()` on the query root, so
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
final class DoctrineFilterHandler implements FilterHandlerInterface, AliasAwareFilterHandler
{
    /**
     * @var list<DoctrineFilterArmInterface>
     */
    private readonly array $arms;

    /**
     * @param iterable<DoctrineFilterArmInterface> $arms author arms for custom filter types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
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
            $filter instanceof Where => $this->where($filter, $query, $value, $alias),
            $filter instanceof WhereIn => $this->whereIn($query, $this->pivotColumn($filter->column, $alias), $this->toList($value, $filter->delimiter), false, $alias),
            $filter instanceof WhereNotIn => $this->whereIn($query, $this->pivotColumn($filter->column, $alias), $this->toList($value, $filter->delimiter), true, $alias),
            $filter instanceof WhereIdIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), false, $alias),
            $filter instanceof WhereIdNotIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), true, $alias),
            $filter instanceof WhereNull => $query->andWhere(\sprintf('%s IS NULL', $this->path($this->pivotColumn($filter->column, $alias), $alias))),
            $filter instanceof WhereNotNull => $query->andWhere(\sprintf('%s IS NOT NULL', $this->path($this->pivotColumn($filter->column, $alias), $alias))),
            $filter instanceof WhereThrough => $this->whereThrough($query, $filter, $value),
            $filter instanceof WhereHas => $this->whereHas($query, $filter->relationship, false),
            $filter instanceof WhereDoesntHave => $this->whereHas($query, $filter->relationship, true),
            $filter instanceof WhereHasMatching => $this->whereHasMatching($query, $filter, $value),
            // Range (and DateRange, which extends it) is a structured min/max filter:
            // two push-down andWhere predicates on the SAME builder, no subquery.
            $filter instanceof Range => $this->range($query, $filter, $value, $alias),
            default => $this->applyArm($filter, $query, $value, $alias),
        };
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

        throw new UnsupportedFilter($filter);
    }

    /**
     * Relationship-existence predicate ({@see WhereHas}/{@see WhereDoesntHave}):
     * the degenerate front-end of {@see existsSubquery()} — a length-1 path (the
     * relationship is the leaf hop) with NO leaf predicate, so a row matches iff
     * it has at least one related row (negated for `WhereDoesntHave`).
     */
    private function whereHas(QueryBuilder $query, string $relationship, bool $negate): QueryBuilder
    {
        $exists = $query->expr()->exists(
            $this->existsSubquery($query, [$relationship], null)->getDQL(),
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
     */
    private function whereThrough(QueryBuilder $query, WhereThrough $filter, mixed $value): QueryBuilder
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;

        $segments = \explode('.', $filter->path);
        if (\count($segments) < 2) {
            throw new \LogicException(\sprintf(
                'WhereThrough filter "%s" needs a dotted path "relationship.attribute"; got "%s".',
                $filter->key(),
                $filter->path,
            ));
        }

        $exists = $query->expr()->exists(
            $this->existsSubquery(
                $query,
                $segments,
                function (QueryBuilder $sub, string $leafAlias, string $leafField) use ($query, $filter, $expected): void {
                    // The predicate TEXT goes on the subquery (via its leaf alias);
                    // the placeholder name and the bound value go on the OUTER $query,
                    // which executes the embedded EXISTS — so a placeholder collision
                    // across several traversal filters on one query cannot happen.
                    $this->applyComparison($query, $sub, $leafAlias . '.' . $leafField, $filter->operator, $expected, $filter->key());
                },
            )->getDQL(),
        );

        return $query->andWhere($exists);
    }

    /**
     * Author-supplied relationship-existence predicate ({@see WhereHasMatching}):
     * a single relationship hop whose related root is narrowed by a {@see \Doctrine\Common\Collections\Criteria}
     * (applied with `addCriteria` on the related root) or a raw-subquery closure.
     * Feeds the SAME {@see existsSubquery()} builder; the related entity stays the
     * subquery root so both narrowing surfaces hang off it naturally.
     */
    private function whereHasMatching(QueryBuilder $query, WhereHasMatching $filter, mixed $value): QueryBuilder
    {
        $criteria = $filter->criteria;
        $build = $filter->build;

        $exists = $query->expr()->exists(
            $this->existsSubquery(
                $query,
                [$filter->relationship],
                static function (QueryBuilder $sub, string $relatedAlias, string $leafField) use ($query, $criteria, $build, $value): void {
                    // The leaf segment IS the related root for a single hop, so
                    // $leafField is empty and ignored: the author narrows the
                    // related root directly.
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
            )->getDQL(),
        );

        return $query->andWhere($exists);
    }

    /**
     * The ONE correlated `EXISTS` subquery builder behind every relationship
     * filter. `$segments` is the dotted path: the first segment is the
     * relationship off the outer root (its TARGET becomes the subquery root —
     * the RELATED entity, so an author predicate hangs off it), each subsequent
     * intermediate segment is a further relationship joined in turn, and the LAST
     * segment is the leaf attribute. A length-1 path is the degenerate case
     * (relationship only, no further hops, no leaf attribute).
     *
     * The subquery roots on the related entity and correlates back to the outer
     * owner by a membership `IN`-subquery on the owning association (uniform for
     * to-one and to-many, owning-side and many-to-many — it never needs the
     * inverse field), so the related root is the builder's first alias and
     * `addCriteria` / the traversal joins both target it.
     *
     * `$applyLeaf` (null for pure existence) receives `(subquery, leafAlias, leafField)`
     * to add the leaf predicate: `leafAlias` is the alias the final hop reached and
     * `leafField` the leaf attribute name (empty for a length-1 path).
     *
     * @param list<string>                                $segments  the dotted path's segments (≥ 1)
     * @param \Closure(QueryBuilder, string, string): void|null $applyLeaf
     */
    private function existsSubquery(QueryBuilder $query, array $segments, ?\Closure $applyLeaf): QueryBuilder
    {
        $rootAlias = $this->rootAlias($query);
        $rootEntity = $query->getRootEntities()[0]
            ?? throw new \LogicException('The QueryBuilder has no root entity to filter on.');

        $entityManager = $query->getEntityManager();
        $ownerMetadata = $entityManager->getClassMetadata($rootEntity);

        // The first segment is the owning relationship; resolve its target as the
        // RELATED root and split off any remaining intermediate relationships and
        // the leaf attribute.
        $firstSegment = $this->assertAssociation($ownerMetadata, $segments[0]);
        $relatedClass = $ownerMetadata->getAssociationTargetClass($firstSegment);

        $subQuery = $entityManager->createQueryBuilder();

        // A per-subquery alias stem so several relationship filters on one query keep
        // distinct subquery scopes (\spl_object_id is unique for the lifetime of this
        // builder, which lives only as long as this DQL string is built).
        $stem = 'jsonapi_has_' . \spl_object_id($subQuery);
        $relatedAlias = $stem . '_rel';

        $subQuery
            ->select('1')
            ->from($relatedClass, $relatedAlias);

        // Correlate the related root back to the outer owner via a membership
        // IN-subquery on the owning association (mirrors RelationScope's subquery
        // branch): the inner-inner subquery roots on the owner, joins the first hop,
        // selects the related ids, and is correlated to the outer root by alias.
        $relatedIdField = $entityManager->getClassMetadata($relatedClass)->getSingleIdentifierFieldName();
        $ownerAlias = $stem . '_owner';
        $membershipAlias = $stem . '_member';
        $membership = $entityManager->createQueryBuilder()
            ->select(\sprintf('%s.%s', $membershipAlias, $relatedIdField))
            ->from($rootEntity, $ownerAlias)
            ->innerJoin(\sprintf('%s.%s', $ownerAlias, $firstSegment), $membershipAlias)
            ->where(\sprintf('%s = %s', $ownerAlias, $rootAlias));
        $subQuery->andWhere($subQuery->expr()->in(
            \sprintf('%s.%s', $relatedAlias, $relatedIdField),
            $membership->getDQL(),
        ));

        // Chain the remaining intermediate relationship segments off the related
        // root, resolving each as an association at build time; the final segment
        // is the leaf attribute (validated as a field, not joined).
        $currentAlias = $relatedAlias;
        $currentMetadata = $entityManager->getClassMetadata($relatedClass);
        $leafField = '';

        $intermediate = \array_slice($segments, 1, -1);
        foreach ($intermediate as $segment) {
            $association = $this->assertAssociation($currentMetadata, $segment);
            $nextAlias = $currentAlias . '_' . $segment;
            $subQuery->innerJoin(\sprintf('%s.%s', $currentAlias, $association), $nextAlias);
            $currentMetadata = $entityManager->getClassMetadata($currentMetadata->getAssociationTargetClass($association));
            $currentAlias = $nextAlias;
        }

        if (\count($segments) > 1) {
            $leafField = $this->assertField($currentMetadata, $segments[\count($segments) - 1]);
        }

        if ($applyLeaf !== null) {
            $applyLeaf($subQuery, $currentAlias, $leafField);
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
     * Asserts `$segment` names a Doctrine field on `$metadata` and returns it,
     * validating the identifier first (the leaf attribute of a traversal path).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function assertField(ClassMetadata $metadata, string $segment): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine field name.', $segment));
        }

        if (!$metadata->hasField($segment)) {
            throw new \LogicException(\sprintf(
                '"%s" is not a field on "%s"; a traversal path\'s final segment must be an attribute.',
                $segment,
                $metadata->getName(),
            ));
        }

        return $segment;
    }

    private function where(Where $filter, QueryBuilder $query, mixed $value, string $alias): QueryBuilder
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;
        $path = $this->path($this->pivotColumn($filter->column, $alias), $alias);

        $this->applyComparison($query, $query, $path, $filter->operator, $expected, $filter->key());

        return $query;
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
        $bindOn->setParameter($placeholder, $expected);
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
            $query->andWhere(\sprintf('%s >= :%s', $path, $placeholder))->setParameter($placeholder, $min);
        }

        if ($max !== null) {
            $placeholder = $this->placeholder($query);
            $query->andWhere(\sprintf('%s <= :%s', $path, $placeholder))->setParameter($placeholder, $max);
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

        return $query
            ->andWhere(\sprintf('%s %s (:%s)', $path, $negate ? 'NOT IN' : 'IN', $placeholder))
            ->setParameter($placeholder, $values);
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
     * convention (bundle ADR 0067): a filter whose column is `pivot.position` is routed
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
