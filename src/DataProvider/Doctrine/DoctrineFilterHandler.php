<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;

/**
 * Executes core's filter value objects against a Doctrine `QueryBuilder`,
 * pushing each predicate down to DQL (`andWhere`, parameter-bound). Semantics
 * mirror core's in-memory `ArrayFilterHandler` — the conformance witness — so
 * the same spec test passes on both providers:
 *
 * - {@see Where} comparison operators map to their DQL equivalents; `like`
 *   means **contains, case-insensitive for ASCII** (the reference contract —
 *   the in-memory handler folds via `stripos`): the value is wildcard-escaped,
 *   wrapped in `%…%`, and both sides are `LOWER()`ed so the behaviour does not
 *   depend on the platform's `LIKE` collation (PostgreSQL's `LIKE` is
 *   case-sensitive, SQLite's folds ASCII only). Case-folding beyond ASCII
 *   remains platform-defined. `==`/`===` both map to `=` — DQL has a single,
 *   type-coercing equality.
 * - {@see WhereIn}/{@see WhereIdIn} with an empty value list match nothing
 *   (`IN ()` is not valid SQL); the negated variants then match everything.
 * - {@see WhereNull}/{@see WhereNotNull} ignore the request value entirely.
 * - The relationship filters (`WhereHas`/`WhereDoesntHave`) are not executable
 *   until the relationship phase and raise {@see UnsupportedFilter} (a 500 —
 *   declaring one today is a server configuration error, not a client mistake).
 *
 * Columns come from the server-side resource declaration, never the client, and
 * are validated as DQL field paths (dots allowed for embeddables) before being
 * interpolated; values are always bound as query parameters.
 *
 * @implements FilterHandlerInterface<QueryBuilder>
 */
final class DoctrineFilterHandler implements FilterHandlerInterface
{
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

        return match (true) {
            $filter instanceof Where => $this->where($filter, $query, $value),
            $filter instanceof WhereIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), false),
            $filter instanceof WhereNotIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), true),
            $filter instanceof WhereIdIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), false),
            $filter instanceof WhereIdNotIn => $this->whereIn($query, $filter->column, $this->toList($value, $filter->delimiter), true),
            $filter instanceof WhereNull => $query->andWhere(\sprintf('%s IS NULL', $this->path($query, $filter->column))),
            $filter instanceof WhereNotNull => $query->andWhere(\sprintf('%s IS NOT NULL', $this->path($query, $filter->column))),
            default => throw new UnsupportedFilter($filter),
        };
    }

    private function where(Where $filter, QueryBuilder $query, mixed $value): QueryBuilder
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;
        $path = $this->path($query, $filter->column);

        if ($filter->operator === 'like') {
            return $this->like($query, $path, $expected);
        }

        $operator = match ($filter->operator) {
            '=', '==', '===' => '=',
            '!=', '<>' => '<>',
            '>', '>=', '<', '<=' => $filter->operator,
            default => throw new \LogicException(\sprintf(
                'Filter "%s" declares operator "%s", which has no DQL equivalent.',
                $filter->key(),
                $filter->operator,
            )),
        };

        $placeholder = $this->placeholder($query);

        return $query
            ->andWhere(\sprintf('%s %s :%s', $path, $operator, $placeholder))
            ->setParameter($placeholder, $expected);
    }

    /**
     * Contains-match, mirroring the in-memory handler's `stripos`: the value's
     * `%`/`_` are literal (escaped with `!`), matching folds ASCII case on both
     * sides (`LOWER()` in DQL + `strtolower` on the bound value, so the result
     * does not depend on the platform's `LIKE` collation), and a non-string
     * value matches nothing — `stripos` requires two strings.
     */
    private function like(QueryBuilder $query, string $path, mixed $expected): QueryBuilder
    {
        if (!\is_string($expected)) {
            return $query->andWhere('1 = 0');
        }

        $placeholder = $this->placeholder($query);
        $escaped = \str_replace(['!', '%', '_'], ['!!', '!%', '!_'], \strtolower($expected));

        return $query
            ->andWhere(\sprintf("LOWER(%s) LIKE :%s ESCAPE '!'", $path, $placeholder))
            ->setParameter($placeholder, '%' . $escaped . '%');
    }

    /**
     * @param list<mixed> $values
     */
    private function whereIn(QueryBuilder $query, string $column, array $values, bool $negate): QueryBuilder
    {
        $path = $this->path($query, $column);

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
     * The DQL path for a declared column on the root entity, validated as an
     * identifier path (dots allowed for embedded fields) so a declaration typo
     * fails loudly rather than reaching the DQL parser interpolated.
     */
    private function path(QueryBuilder $query, string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine field path.', $column));
        }

        return $this->rootAlias($query) . '.' . $column;
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
