<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Moves a date-time into the zone a naive Doctrine `datetime` column is stored in,
 * before it is bound to a query or persisted: the configured `storage_timezone`, or
 * PHP's default timezone when none is set.
 *
 * `datetime` and `datetime_immutable` write `Y-m-d H:i:s` and drop the offset, and
 * Doctrine reads them back in PHP's default timezone. A client value like
 * `2026-10-01T15:00:00Z` therefore has to become the same instant in that zone first,
 * or a filter compares against the wrong wall-clock time and a write stores one. With
 * no zone configured, the default is read when each value is converted, never cached,
 * because an application may switch it per request.
 *
 * Only the naive date-time types convert, including subclasses. A `datetimetz` column
 * keeps its own offset handling. A `date` column never converts, because moving a
 * midnight bound into another zone can change its calendar day: a date bound binds
 * with the column's own type instead, so it compares as the day the client wrote.
 *
 * @internal
 */
final class NaiveDateTimeStorage
{
    /**
     * @param ?string $timezone the zone naive columns are stored in; null for PHP's default timezone
     */
    public function __construct(
        private readonly ?string $timezone = null,
    ) {}

    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone ?? \date_default_timezone_get());
    }

    /**
     * Whether `$type`, a DBAL type name, stores a date-time without its offset.
     */
    public function isNaive(?string $type): bool
    {
        if ($type === null || !Type::hasType($type)) {
            return false;
        }

        $instance = Type::getType($type);

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
     * `$type`. A naive date-time moves into the storage zone. A date bound for a
     * `date` column binds with that type, so it is written as its own `Y-m-d` rather
     * than a date-time string that a text-compared column such as SQLite's would
     * order after the stored day. Anything else binds as before, with an inferred type.
     *
     * @return array{mixed, ?string}
     */
    public function parameter(mixed $value, ?string $type): array
    {
        if (!$value instanceof \DateTimeInterface || $type === null || !Type::hasType($type)) {
            return [$value, null];
        }

        $instance = Type::getType($type);

        if ($instance instanceof DateImmutableType) {
            return [\DateTimeImmutable::createFromInterface($value), $type];
        }

        if ($instance instanceof DateType) {
            return [\DateTime::createFromInterface($value), $type];
        }

        return [$this->toStorage($value, $type), null];
    }

    /**
     * Moves every naive date-time field of `$entity` into the storage zone, writing a
     * field back only when its zone differs, so a value Doctrine itself hydrated is
     * left untouched and never shows up as a change.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function normaliseEntity(ClassMetadata $metadata, object $entity): void
    {
        $zone = $this->zone()->getName();

        foreach ($metadata->getFieldNames() as $field) {
            if (!$this->isNaive($metadata->getTypeOfField($field))) {
                continue;
            }

            $value = $metadata->getFieldValue($entity, $field);
            if (!$value instanceof \DateTimeInterface || $value->getTimezone()->getName() === $zone) {
                continue;
            }

            try {
                $metadata->setFieldValue($entity, $field, $this->toStorage($value, $metadata->getTypeOfField($field)));
            } catch (\Error) {
                // A readonly property keeps the value its constructor gave it.
            }
        }
    }
}
