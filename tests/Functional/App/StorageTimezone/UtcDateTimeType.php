<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeType;

/**
 * A `datetime` column an application keeps in UTC, like the types convertr registers
 * under `doctrine.dbal.types`. It writes every value as UTC digits and reads them back
 * as UTC, whatever PHP's default timezone is.
 */
final class UtcDateTimeType extends DateTimeType
{
    public const string NAME = 'datetime_utc';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTime) {
            $value = (clone $value)->setTimezone(new \DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTime
    {
        if ($value === null || $value instanceof \DateTime) {
            return $value;
        }

        \assert(\is_string($value));

        return new \DateTime($value, new \DateTimeZone('UTC'));
    }

    // DBAL 3 names a type through this method; DBAL 4 only uses the registered name.
    public function getName(): string
    {
        return self::NAME;
    }
}
