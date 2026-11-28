<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The nested pivot include conformance in memory, plus `staff`, whose stored fields come from
 * the pivot rows the in-memory store keeps beside each crew.
 */
final class InMemoryNestedPivotIncludeTest extends NestedPivotIncludeConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CpInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aMixedPivotReachedThroughANestedIncludeRendersItsStoredAndComputedFields(): void
    {
        $document = $this->get('/cp-ships?include=crew.staff');

        self::assertSame(['1' => ['shift' => 2, 'note' => 'EARLY', 'role' => 'Reds:Ada'], '3' => ['shift' => 5, 'note' => 'LATE', 'role' => 'Reds:Linus']], $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'staff')));
        self::assertSame(['1' => ['shift' => 4, 'note' => null, 'role' => 'Blues:Ada'], '2' => ['shift' => 6, 'note' => null, 'role' => 'Blues:Grace']], $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'staff')));
    }
}
