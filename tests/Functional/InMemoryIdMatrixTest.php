<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see IdMatrixConformanceTestCase} in memory, where rows hold the value objects a
 * Doctrine hydration gives, and an existence filter reads a foreign-key relation through
 * the related type's provider, as Doctrine does.
 */
final class InMemoryIdMatrixTest extends IdMatrixConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdMatrixInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();
        IdMatrixInMemoryTestKernel::reset();
    }

    #[Test]
    public function aSortReadsAToOneThroughItsForeignKey(): void
    {
        $ids = function (string $sort): array {
            $data = $this->decode($this->handle('/im-posts?sort=' . $sort))['data'] ?? null;
            self::assertIsArray($data);

            return \array_map(static fn(mixed $row): mixed => \is_array($row) ? $row['id'] ?? null : null, $data);
        };

        // Post 2 has no note, so it sorts as null; post 1 sorts by its first note's title.
        self::assertSame(['2', '1'], $ids('firstNoteTitle'));
        self::assertSame(['1', '2'], $ids('-firstNoteTitle'));
    }
}
