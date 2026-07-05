<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see PivotRelatedCursorConformanceTestCase} against the in-memory provider —
 * the witness half: the SAME `pivotWidgets` declaration pages through the PLAIN
 * keyset execution (the members read off the parent's `widgets` property),
 * because the in-memory provider is not pivot-aware — the documented pivot
 * boundary. The page walks are byte-identical to the Doctrine pivot push-down's;
 * only the pivot vocabulary/meta differ (asserted via `expectsPivotMeta()` and
 * the 400 below).
 */
final class InMemoryPivotRelatedCursorTest extends PivotRelatedCursorConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }

    protected function expectsPivotMeta(): bool
    {
        return false;
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aPivotSortStaysUnrecognisedInMemory(): void
    {
        // The pivot sort vocabulary merges ONLY on a pivot-aware provider's
        // related endpoint: in-memory, `?sort=slot` stays an unrecognised sort
        // key (400) — the cursor path validates `?sort` through the same keyset
        // resolver, so the boundary holds on a cursor-paginated pivot relation.
        $response = $this->handle('/cursorShelves/1/pivotWidgets?sort=slot');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }
}
