<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\DateTimeTzType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\Type;

/**
 * Prepares a client date-time for a Doctrine date column, before it is bound to a query
 * or written to an entity.
 *
 * `datetime` and `datetime_immutable` write `Y-m-d H:i:s` and drop the offset, and
 * Doctrine reads them back in PHP's default timezone. A client value like
 * `2026-10-01T15:00:00Z` therefore has to become the same instant in that zone first,
 * or a filter compares against the wrong wall-clock time and a write stores one. The
 * zone is read when each value is converted, never cached, because an application may
 * switch the default timezone per request.
 *
 * Only the naive date-time types convert, including subclasses. A `datetimetz` column
 * keeps the value's offset, and a `date` column keeps the bound's own calendar day,
 * because moving a midnight bound into another zone can change its day. Query
 * parameters bind with the column's mapped type, since Doctrine otherwise infers the
 * type from the value and formats a `datetimetz` bound without its offset.
 *
 * @internal
 */
final class NaiveDateTimeStorage
{
    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone(\date_default_timezone_get());
    }

    /**
     * Whether `$type`, a DBAL type name, stores a date-time without its offset.
     */
    public function isNaive(?string $type): bool
    {
        $instance = $this->type($type);

        return $instance instanceof DateTimeType || $instance instanceof DateTimeImmutableType;
    }

    /**
     * `$value` moved into the storage zone when it is a date-time bound for a naive
     * column of DBAL type `$type`; anything else is returned unchanged. A mutable
     * `\DateTime` is copied, never moved in place, and keeps its class.
     */
    public function toStorage(mixed $value, ?string $type): mixed
    {
        if (!$value instanceof \DateTimeInterface || !$this->isNaive($type)) {
            return $value;
        }

        if ($value instanceof \DateTime) {
            return (clone $value)->setTimezone($this->zone());
        }

        return \DateTimeImmutable::createFromInterface($value)->setTimezone($this->zone());
    }

    /**
     * The value and explicit DBAL type to bind `$value` with against a column of type
     * `$type`. A date-time bound for a date, date-time or zoned date-time column binds
     * with that column's type, as the PHP class the type converts, and moves into the
     * storage zone first when the column is naive. Anything else binds as before, with
     * a type Doctrine infers from the value.
     *
     * @return array{mixed, ?string}
     */
    public function parameter(mixed $value, ?string $type): array
    {
        if (!$value instanceof \DateTimeInterface || $type === null || $this->dateClass($type) === null) {
            return [$value, null];
        }

        $value = $this->toStorage($value, $type);
        \assert($value instanceof \DateTimeInterface);

        return [$this->asColumnClass($value, $type), $type];
    }

    /**
     * `$value` as the PHP class a date, date-time or zoned date-time column of DBAL type
     * `$type` converts, keeping its instant and offset. DBAL 4 rejects a
     * `\DateTimeImmutable` for a mutable type and the reverse. A value for any other
     * type is returned unchanged.
     */
    public function asColumnClass(mixed $value, ?string $type): mixed
    {
        if (!$value instanceof \DateTimeInterface) {
            return $value;
        }

        return match ($this->dateClass($type)) {
            \DateTimeImmutable::class => \DateTimeImmutable::createFromInterface($value),
            \DateTime::class => \DateTime::createFromInterface($value),
            default => $value,
        };
    }

    /**
     * The date class the date, date-time or zoned date-time type `$type` converts,
     * including subclasses, or null for any other type.
     *
     * @return class-string<\DateTimeInterface>|null
     */
    private function dateClass(?string $type): ?string
    {
        $instance = $this->type($type);

        if ($instance instanceof DateTimeImmutableType
            || $instance instanceof DateTimeTzImmutableType
            || $instance instanceof DateImmutableType) {
            return \DateTimeImmutable::class;
        }

        if ($instance instanceof DateTimeType
            || $instance instanceof DateTimeTzType
            || $instance instanceof DateType) {
            return \DateTime::class;
        }

        return null;
    }

    private function type(?string $type): ?Type
    {
        return $type !== null && Type::hasType($type) ? Type::getType($type) : null;
    }
}
