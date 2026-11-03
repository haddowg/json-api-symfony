<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

/**
 * What a {@see DoctrineKeysetSortArmInterface} hands {@see DoctrineKeyset} for one
 * custom sort level of a cursor page: the DQL expression the level orders and
 * compares by, and the DBAL type its cursor boundary value binds with.
 *
 * The expression appears in `CASE WHEN <dql> IS NULL ...`, in `ORDER BY` and on the
 * left of `=`, `<` and `>`, so it must be a field path (`value_cap.amount`), a DQL
 * function call (`CAST_INTEGER(value_cap.value)`, with the function registered by the
 * application) or a `COALESCE`/`NULLIF`. A row the expression yields null for sorts as the
 * largest value, as a null column does.
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
     * @param string  $dql  the expression the level orders and compares by
     * @param ?string $type the DBAL type name the boundary value binds with, `null` to let Doctrine infer it
     */
    public function __construct(
        public string $dql,
        public ?string $type = null,
    ) {}
}
