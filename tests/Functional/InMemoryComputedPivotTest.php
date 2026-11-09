<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The computed-only `members` conformance in memory, plus `staff`, the mixed pivot the
 * Doctrine kernel serves. The in-memory store keeps no pivot storage, so each member
 * renders the computed `role` alone: never the stored `shift`, nor the stored `note` an
 * extract hook reads.
 */
final class InMemoryComputedPivotTest extends ComputedPivotConformanceTestCase
{
    private const array STAFF = ['1' => ['role' => 'Reds:Ada'], '3' => ['role' => 'Reds:Linus']];

    #[Test]
    public function theRelatedEndpointRendersOnlyTheComputedMembersOfAMixedPivot(): void
    {
        self::assertSame(self::STAFF, $this->pivots($this->get('/cp-crews/1/staff')));
    }

    #[Test]
    public function theRelationshipEndpointRendersOnlyTheComputedMembersOfAMixedPivot(): void
    {
        self::assertSame(self::STAFF, $this->pivots($this->get('/cp-crews/1/relationships/staff')));
    }

    #[Test]
    public function anIncludedLinkageRendersOnlyTheComputedMembersOfAMixedPivot(): void
    {
        self::assertSame(self::STAFF, $this->pivots($this->linkage($this->get('/cp-crews/1?include=staff'), 'staff')));
    }

    #[Test]
    public function aStoredPivotFieldIsStillNoSortKeyInMemory(): void
    {
        self::assertSame(400, $this->handle('/cp-crews/1/staff?sort=shift')->getStatusCode());
    }

    protected static function getKernelClass(): string
    {
        return CpInMemoryTestKernel::class;
    }
}
