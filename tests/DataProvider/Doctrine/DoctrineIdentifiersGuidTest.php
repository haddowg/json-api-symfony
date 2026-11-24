<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineIdentifiers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineIdentifiers::class)]
final class DoctrineIdentifiersGuidTest extends TestCase
{
    #[Test]
    public function aMalformedGuidDoesNotFitANativeUuidColumn(): void
    {
        $guid = Type::getType('guid');

        self::assertFalse(DoctrineIdentifiers::fitsNativeGuid($guid, new PostgreSQLPlatform(), 'not-a-uuid'));
        self::assertTrue(DoctrineIdentifiers::fitsNativeGuid($guid, new PostgreSQLPlatform(), '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c81'));
        self::assertTrue(DoctrineIdentifiers::fitsNativeGuid($guid, new PostgreSQLPlatform(), '2D8C3E4A8CAB4F779C8A3B4F5E6D7C81'));
    }

    #[Test]
    public function aGuidInATextColumnOrAnotherTypeIsBoundAsItIs(): void
    {
        self::assertTrue(DoctrineIdentifiers::fitsNativeGuid(Type::getType('guid'), new MySQLPlatform(), 'not-a-uuid'));
        self::assertTrue(DoctrineIdentifiers::fitsNativeGuid(Type::getType('guid'), new SQLitePlatform(), 'not-a-uuid'));
        self::assertTrue(DoctrineIdentifiers::fitsNativeGuid(Type::getType('string'), new PostgreSQLPlatform(), 'not-a-uuid'));
    }
}
