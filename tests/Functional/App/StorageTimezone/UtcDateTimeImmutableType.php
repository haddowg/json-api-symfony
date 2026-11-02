<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * The immutable counterpart of {@see UtcDateTimeType}: UTC digits on write, read back
 * as UTC.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'datetime_immutable_utc';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(new \DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        \assert(\is_string($value));

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    // DBAL 3 names a type through this method; DBAL 4 only uses the registered name.
    public function getName(): string
    {
        return self::NAME;
    }
}
