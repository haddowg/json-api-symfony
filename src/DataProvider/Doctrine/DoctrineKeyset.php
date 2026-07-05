<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Collection\Keyset\KeysetColumn;
use haddowg\JsonApi\Pagination\CursorBoundary;

/**
 * Builds the Doctrine push-down for a cursor (keyset) page — the forced
 * NULL=largest `ORDER BY` and the IS-NULL-branched lexicographic keyset `WHERE`
 * — matching the in-memory witness ({@see \haddowg\JsonApi\Collection\Keyset\InMemoryKeyset})
 * byte-for-byte (bundle ADR 0063 / core ADR 0123).
 *
 * The order is forced as a PORTABLE NULL=largest (NOT `NULLS LAST`, which
 * MySQL/SQLite lack): each column emits a leading `CASE WHEN c IS NULL THEN 1
 * ELSE 0 END` boolean term then the column, both in the column's direction — so
 * ascending puts non-nulls (0) before nulls (1) and descending reverses, every
 * engine ordering the 0/1 identically. The keyset `WHERE` is the lexicographic
 * indicator of "strictly after the boundary under that order": an `orX` over
 * levels, each level pinning the higher-significance columns to the boundary
 * (null-aware `IS NULL` equality) and requiring column i to be strictly after on
 * its own — the four AFTER cases. The final (PK) level is the plain `id >/< :v`
 * tiebreak (the PK is never null), so two rows tied on every sort column are
 * still totally ordered.
 *
 * Date/UUID boundary values are bound with the column's DBAL type (from
 * {@see ClassMetadata::getTypeOfField()}) so the comparison is type-correct, not
 * a lexical string compare against a datetime/binary column. Each boundary value
 * binds a FRESH placeholder per occurrence (it repeats across OR branches) in a
 * `jsonapi_cursor_N` space distinct from the filter handler's.
 *
 * A keyset column normally lives on the ROOT alias (the fetched entity). The
 * pivot related-collection cursor walks a keyset that can span TWO aliases —
 * the far entity at the root plus the association entity joined as `pivot` —
 * so a column-keyed `$aliasOfColumn` map (derived from the criteria's `aliasOf`
 * routing at SQL-build time, since a {@see KeysetColumn} deliberately carries
 * only column + direction) routes each column to its alias, and
 * `$metadataOfAlias` supplies that alias's entity metadata so the boundary
 * value still binds with the RIGHT DBAL type. Both default empty, so every
 * non-pivot keyset is byte-identical to before.
 */
final class DoctrineKeyset
{
    private int $bindCount = 0;

    /**
     * @param ClassMetadata<object>                 $metadata        the root entity's metadata (column → DBAL type)
     * @param array<string, string>                 $aliasOfColumn   keyset column → non-root query alias; an absent column resolves to the root alias
     * @param array<string, ClassMetadata<object>>  $metadataOfAlias non-root alias → its entity's metadata (for typed boundary binds)
     */
    public function __construct(
        private readonly ClassMetadata $metadata,
        private readonly string $rootAlias,
        private readonly array $aliasOfColumn = [],
        private readonly array $metadataOfAlias = [],
    ) {}

    /**
     * Emits the forced NULL=largest `ORDER BY` for `$columns` onto `$builder`.
     * Per column a leading `CASE WHEN <c> IS NULL THEN 1 ELSE 0 END` term then the
     * column, both in the column's direction. (A bare `(c IS NULL)` is not a legal
     * DQL `ORDER BY` scalar on every platform; the `CASE` form orders identically.)
     *
     * @param list<KeysetColumn> $columns
     */
    public function orderBy(QueryBuilder $builder, array $columns): void
    {
        foreach ($columns as $column) {
            $direction = $column->descending ? 'DESC' : 'ASC';
            $path = $this->path($column->column);
            $builder->addOrderBy(\sprintf('CASE WHEN %s IS NULL THEN 1 ELSE 0 END', $path), $direction);
            $builder->addOrderBy($path, $direction);
        }
    }

    /**
     * Applies the keyset `WHERE` for "strictly after `$boundary` under the order of
     * `$columns`" onto `$builder` (the boundary's values are bound with their
     * columns' DBAL types). A no-op when there is no boundary (the first page).
     *
     * @param list<KeysetColumn> $columns
     */
    public function applyAfter(QueryBuilder $builder, CursorBoundary $boundary, array $columns): void
    {
        $orParts = [];

        foreach ($columns as $level => $column) {
            // Column i is strictly after the boundary on its own (the four cases).
            // Resolve this BEFORE binding the EQ prefix: the asc + null boundary
            // degenerate drops the whole level, and the prefix's bound parameters
            // would otherwise be orphaned (bound but referenced nowhere in the DQL,
            // which Doctrine rejects with "Too many parameters").
            $after = $this->after($builder, $column, $boundary->values[$column->column] ?? null);
            if ($after === null) {
                // asc + null boundary contributes 1=0: this level matches nothing
                // by its own after-term. Skip it entirely (an empty andX over only
                // the prefix would wrongly match the equality-only rows).
                continue;
            }

            $andParts = [];

            // The equality prefix: every higher-significance column equals the
            // boundary (a null boundary value → IS NULL, never `= null`).
            for ($i = 0; $i < $level; $i++) {
                $higher = $columns[$i];
                $andParts[] = $this->equals($builder, $higher->column, $boundary->values[$higher->column] ?? null);
            }

            $andParts[] = $after;

            $orParts[] = \count($andParts) === 1 ? $andParts[0] : (string) $builder->expr()->andX(...$andParts);
        }

        if ($orParts === []) {
            // Every level was the asc+null degenerate (1=0): no row is strictly
            // after this boundary. Match nothing.
            $builder->andWhere('1 = 0');

            return;
        }

        $builder->andWhere($builder->expr()->orX(...$orParts));
    }

    /**
     * The null-aware equality predicate for the keyset's EQ prefix: a null
     * boundary value is `IS NULL` (never `= :v`, which is UNKNOWN and would drop
     * the row); a non-null value binds a typed placeholder.
     */
    private function equals(QueryBuilder $builder, string $column, mixed $value): string
    {
        $path = $this->path($column);
        if ($value === null) {
            return \sprintf('%s IS NULL', $path);
        }

        $placeholder = $this->bind($builder, $column, $value);

        return \sprintf('%s = :%s', $path, $placeholder);
    }

    /**
     * The "strictly after the boundary on this column alone" predicate under the
     * forced NULL=largest order for the column's direction — the four AFTER cases.
     * Returns `null` for the asc + null-boundary degenerate (1=0), which the
     * caller drops from the OR.
     */
    private function after(QueryBuilder $builder, KeysetColumn $column, mixed $value): ?string
    {
        $path = $this->path($column->column);

        if (!$column->descending) {
            if ($value === null) {
                // A null is the maximal asc element: nothing is strictly after it
                // on this column alone (the tie is carried by later levels' IS NULL
                // prefix). Drop this level.
                return null;
            }

            // Non-nulls greater than the boundary, plus ALL nulls (nulls follow).
            $placeholder = $this->bind($builder, $column->column, $value);

            return \sprintf('(%s > :%s OR %s IS NULL)', $path, $placeholder, $path);
        }

        if ($value === null) {
            // A null is first in desc; after it come ALL non-nulls.
            return \sprintf('%s IS NOT NULL', $path);
        }

        // Only smaller non-nulls follow (nulls are first in desc, already before).
        $placeholder = $this->bind($builder, $column->column, $value);

        return \sprintf('%s < :%s', $path, $placeholder);
    }

    /**
     * Binds `$value` for `$column` to a fresh `jsonapi_cursor_N` placeholder with
     * the column's DBAL type, so a date/UUID compares type-correctly (not a lexical
     * string compare against a datetime/binary column). Returns the placeholder name.
     *
     * The boundary value arrives as the JSON-safe WIRE form the codec carries (a
     * datetime is an ISO-8601 string, a uuid a string), but a typed DBAL parameter
     * converts a PHP value (`DateTimeImmutable`) to SQL — so the wire value is first
     * coerced back to the column's PHP form via the DBAL {@see Type::convertToPHPValue()}
     * before binding, then bound WITH the type so DBAL emits the type-correct literal.
     */
    private function bind(QueryBuilder $builder, string $column, mixed $value): string
    {
        $placeholder = 'jsonapi_cursor_' . $this->bindCount++;

        $metadata = $this->metadataFor($column);
        $type = $metadata->hasField($column) ? $metadata->getTypeOfField($column) : null;

        if ($type !== null) {
            $value = $this->toPhpValue($value, $type);
        }

        $builder->setParameter($placeholder, $value, $type);

        return $placeholder;
    }

    /**
     * Coerces the wire boundary value back to the PHP form the DBAL `$type`
     * converts to SQL. A datetime-family type's ISO-8601 wire string becomes a
     * `DateTimeImmutable` (a typed DBAL parameter converts a PHP value, not the
     * wire string — binding the raw string against a datetime column errors), so a
     * boundary date compares chronologically, not lexically. Every other type's
     * value passes through unchanged (an int/uuid string binds as-is); a string
     * that does not parse as a date is bound verbatim (the lenient fallback).
     */
    private function toPhpValue(mixed $value, string $type): mixed
    {
        if (!\is_string($value) || !$this->isDateType($type)) {
            return $value;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * Whether `$type` is a datetime-family DBAL type whose typed binding needs a
     * PHP `DateTimeInterface` value (so the wire ISO-8601 string must be parsed).
     * Matched by name so a custom datetime type registered under a date-ish name
     * is covered without enumerating every DBAL class.
     */
    private function isDateType(string $type): bool
    {
        return \str_contains(\strtolower($type), 'date') || \str_contains(\strtolower($type), 'time');
    }

    private function path(string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid Doctrine field path.', $column));
        }

        return ($this->aliasOfColumn[$column] ?? $this->rootAlias) . '.' . $column;
    }

    /**
     * The metadata whose field types bind `$column`'s boundary values: the routed
     * alias's entity metadata for a non-root (pivot) column, the root entity's
     * otherwise.
     *
     * @return ClassMetadata<object>
     */
    private function metadataFor(string $column): ClassMetadata
    {
        $alias = $this->aliasOfColumn[$column] ?? null;

        return $alias !== null ? ($this->metadataOfAlias[$alias] ?? $this->metadata) : $this->metadata;
    }
}
