<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * A column that stores Paris wall-clock time and hands it back in the Europe/Paris
 * zone, the "storage keeps local times" case a server output timezone exists for.
 * SQLite drops the offset of a `datetimetz` column, so the stock types cannot carry
 * a non-UTC zone through the round trip.
 */
final class ParisDateTimeType extends DateTimeImmutableType
{
    public const string NAME = 'paris_datetime';

    private const string FORMAT = 'Y-m-d H:i:s';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (!$value instanceof \DateTimeInterface) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($value)->setTimezone(self::zone())->format(self::FORMAT);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::FORMAT, $value, self::zone());

        return $parsed === false ? null : $parsed;
    }

    private static function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Paris');
    }
}
