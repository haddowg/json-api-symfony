<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\BinaryUuidTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see BinaryUuidConformanceTestCase} with no id encoder: the Doctrine layer
 * converts the wire id through the `uuid` type itself.
 */
#[Group('spec:crud')]
final class DoctrineBinaryUuidTest extends BinaryUuidConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return BinaryUuidTestKernel::class;
    }

    #[Test]
    public function aScopedUuidTargetComparesItsIdAsTextInOneStatement(): void
    {
        $statements = $this->statementsOf('/notes?filter[subject][type]=labels');

        $distinct = \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'select distinct'));
        self::assertSame([], $distinct, "no stored ids are read first:\n" . \implode("\n", $statements));
        // hex() on SQLite, BIN_TO_UUID() on MySQL 8 and a cast on PostgreSQL.
        $asText = static fn(string $sql): bool => \str_contains($sql, 'hex(') || \str_contains($sql, 'bin_to_uuid(') || \str_contains($sql, 'as text)');
        $compared = \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'from uuid_note') && $asText($sql) && \str_contains($sql, 'from uuid_label'));
        self::assertCount(1, $compared, "one statement compares the ids as text:\n" . \implode("\n", $statements));
    }

}
