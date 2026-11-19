<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * A persister whose storage needs a client date-time in a particular form before it is
 * written. The write handler asks it about each `DateTime` attribute the request sends,
 * before hydration, so only values the client wrote are touched. The Doctrine persister
 * uses it to move a value into the zone a naive `datetime` column is stored in, and to
 * store a union in core's stored form in a `json` column.
 *
 * @internal
 */
interface StoresDateTimesInterface
{
    /**
     * The value to write to `$column` of `$entity` for the client date-time `$value`.
     */
    public function dateTimeForColumn(object $entity, string $column, mixed $value): mixed;

    /**
     * Whether `$column` of `$entity` can hold a date-time object. A `Union` written to a
     * column that cannot is stored in core's stored form.
     */
    public function holdsDateTimes(object $entity, string $column): bool;
}
