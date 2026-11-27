<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImGuidOwner;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImNote;
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

    protected function seedCapitalGuidOwner(): void
    {
        IdMatrixInMemoryTestKernel::provider('im-guid-owners')->store()->save(new ImGuidOwner(self::CAPITAL_GUID, 'Capital'));
        $note = new ImNote(5, 'Five', null, null, null, null, self::CAPITAL_GUID, null, null, null, 'im-posts', 2);
        $note->refType = 'im-guid-owners';
        $note->refId = self::CAPITAL_GUID;
        IdMatrixInMemoryTestKernel::provider('im-notes')->store()->save($note);
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
