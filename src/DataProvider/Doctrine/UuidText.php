<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * A UUID id column read as its canonical lowercase text in SQL, so a text column holding
 * UUIDs, such as a polymorphic id, compares with it in one statement. It covers Symfony's
 * `uuid` type, a native uuid column or 16 bytes depending on the platform, and a `guid`
 * the platform keeps as a native uuid. A pair it doesn't cover compares another way.
 *
 * @internal
 */
final class UuidText
{
    /**
     * The DQL function wrapping an id path in {@see UuidTextFunction}.
     */
    public const string FUNCTION = 'JSONAPI_UUID_TEXT';

    /**
     * Whether an id column of the Doctrine type `$type` reads as canonical text on
     * `$platform`.
     */
    public static function supports(AbstractPlatform $platform, ?string $type): bool
    {
        return match ($type) {
            'uuid' => self::native($platform) ? $platform instanceof PostgreSQLPlatform : self::bytes($platform, 'x') !== null,
            'guid' => self::native($platform) && $platform instanceof PostgreSQLPlatform,
            default => false,
        };
    }

    /**
     * The SQL reading the uuid column `$sql` as canonical lowercase text on `$platform`.
     *
     * @throws \LogicException on a platform {@see supports()} refuses
     */
    public static function sql(AbstractPlatform $platform, string $sql): string
    {
        if (self::native($platform)) {
            if ($platform instanceof PostgreSQLPlatform) {
                return \sprintf('CAST(%s AS TEXT)', $sql);
            }
        } elseif (($bytes = self::bytes($platform, $sql)) !== null) {
            return $bytes;
        }

        throw new \LogicException(\sprintf('A uuid id can\'t be read as text on %s.', $platform::class));
    }

    /**
     * Whether the platform keeps a uuid in a native column, as Symfony's uid types decide.
     */
    private static function native(AbstractPlatform $platform): bool
    {
        return $platform->getGuidTypeDeclarationSQL([]) !== $platform->getStringTypeDeclarationSQL(['fixed' => true, 'length' => 36]);
    }

    /**
     * The canonical text of the 16 bytes in `$sql`, or null on a platform with no way to
     * write it.
     */
    private static function bytes(AbstractPlatform $platform, string $sql): ?string
    {
        if ($platform instanceof MySQL80Platform && !self::mariaDb($platform)) {
            return \sprintf('BIN_TO_UUID(%s)', $sql);
        }

        $parts = [];
        foreach ([[1, 8], [9, 4], [13, 4], [17, 4], [21, 12]] as [$from, $length]) {
            $parts[] = \sprintf('SUBSTR(HEX(%s), %d, %d)', $sql, $from, $length);
        }

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => \sprintf('LOWER(CONCAT(%s))', \implode(", '-', ", $parts)),
            $platform instanceof SQLitePlatform => \sprintf('LOWER(%s)', \implode(" || '-' || ", $parts)),
            default => null,
        };
    }

    private static function mariaDb(AbstractPlatform $platform): bool
    {
        return \is_a($platform, 'Doctrine\DBAL\Platforms\MariaDBPlatform') || \is_a($platform, 'Doctrine\DBAL\Platforms\MariaDb1027Platform');
    }
}
