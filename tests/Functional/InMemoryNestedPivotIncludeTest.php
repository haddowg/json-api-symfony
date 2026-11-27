<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The nested pivot include conformance in memory, plus `staff`, which keeps no pivot
 * storage there and so renders only its computed `role` through a nested include.
 */
final class InMemoryNestedPivotIncludeTest extends NestedPivotIncludeConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CpInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aMixedPivotReachedThroughANestedIncludeRendersItsComputedFields(): void
    {
        $document = $this->get('/cp-ships?include=crew.staff');

        self::assertSame(['1' => ['role' => 'Reds:Ada'], '3' => ['role' => 'Reds:Linus']], $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'staff')));
        self::assertSame(['1' => ['role' => 'Blues:Ada'], '2' => ['role' => 'Blues:Grace']], $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'staff')));
    }
}
