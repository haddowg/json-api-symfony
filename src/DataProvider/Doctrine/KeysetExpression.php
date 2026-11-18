<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

/**
 * What a {@see DoctrineKeysetSortArmInterface} hands {@see DoctrineKeyset} for one
 * custom sort level of a cursor page: the DQL expression the level orders and
 * compares by, and the DBAL type its cursor boundary value binds with.
 *
 * The expression is any scalar DQL: a field path (`value_cap.amount`), a function call
 * (`CAST_INTEGER(value_cap.value)`, with the function registered by the application),
 * `CASE`, `COALESCE`, `NULLIF`, arithmetic or a subquery in parentheses. Anything but a
 * field path is selected `AS HIDDEN` and ordered by its result variable. It can't be an
 * aggregate of the query's own rows, since the keyset compares it in `WHERE`. A row the
 * expression yields null for sorts as the largest value, as a null column does.
 *
 * The cursor carries the sort's `keysetValue()` for the boundary row. With
 * `$valueFromQuery` it carries the value the query selected for that row instead, so a
 * value PHP can't compute from the entity, such as a subquery's count, still pages. The
 * selected value is converted with `$type` when one is given.
 *
 * The boundary value arrives in its cursor token form. With `$type` null it binds as
 * is. A date or date-time `$type` parses the token's RFC 3339 string back to the
 * date class that type converts, and a naive `datetime`/`datetime_immutable` type
 * also moves it into PHP's default timezone, the zone Doctrine reads such a column
 * in. Any other type binds the token value with that type.
 */
final readonly class KeysetExpression
{
    /**
     * @param string  $dql            the expression the level orders and compares by
     * @param ?string $type           the DBAL type name the boundary value binds with, `null` to let Doctrine infer it
     * @param bool    $valueFromQuery whether the cursor reads the boundary row's value from the query rather than the sort's `keysetValue()`
     * @param ?\Closure(string): string $rebuild builds the expression again with its identification variables suffixed, see {@see repeatable()}
     */
    public function __construct(
        public string $dql,
        public ?string $type = null,
        public bool $valueFromQuery = false,
        private ?\Closure $rebuild = null,
    ) {}

    /**
     * An expression that declares its own identification variables, such as a subquery's
     * `FROM Member m`. DQL refuses a variable declared twice in one query, and the keyset
     * writes the expression in several places, so `$dql` builds it for each with a suffix
     * to add to every variable it declares.
     *
     * @param \Closure(string $suffix): string $dql
     */
    public static function repeatable(\Closure $dql, ?string $type = null, bool $valueFromQuery = false): self
    {
        return new self($dql(''), $type, $valueFromQuery, $dql);
    }

    /**
     * The expression for its `$use`th place in a query.
     */
    public function dqlFor(int $use): string
    {
        return $this->rebuild !== null ? ($this->rebuild)('_' . $use) : $this->dql;
    }
}
