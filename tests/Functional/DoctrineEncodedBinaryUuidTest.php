<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\EncodedBinaryUuidTestKernel;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * {@see BinaryUuidConformanceTestCase} with an id encoder that decodes the wire id
 * to its 16-byte form, which the Doctrine layer then binds through the `uuid` type.
 */
#[Group('spec:crud')]
final class DoctrineEncodedBinaryUuidTest extends BinaryUuidConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EncodedBinaryUuidTestKernel::class;
    }

    #[Test]
    public function anEncodedUuidTargetAdmitsTheStoredIds(): void
    {
        $statements = $this->statementsOf('/notes?filter[subject][type]=labels');

        $distinct = \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'select distinct') && \str_contains($sql, 'uuid_note'));
        self::assertCount(1, $distinct, "the distinct stored ids are read once:\n" . \implode("\n", $statements));
        self::assertSame([], \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'hex(')), "nothing is compared as text in SQL:\n" . \implode("\n", $statements));
    }

    /**
     * Odd notes hold the 16-byte storage key, even ones the canonical wire id. Only SQLite
     * keeps raw bytes in a text column, so elsewhere every note holds the wire id.
     */
    protected function storedMorphKey(int $note, string $wireId): string
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $sqlite = $entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform;

        return $sqlite && $note % 2 === 1 ? Uuid::fromString($wireId)->toBinary() : $wireId;
    }
}
