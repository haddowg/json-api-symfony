<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The computed-only `members` conformance in memory, plus `staff`, the mixed pivot the
 * Doctrine kernel serves. The in-memory store keeps its pivot rows beside each crew, so each
 * member renders the stored `shift`, the stored `note` its extract hook reads and the
 * computed `role`.
 */
final class InMemoryComputedPivotTest extends ComputedPivotConformanceTestCase
{
    private const array STAFF = ['1' => ['shift' => 2, 'note' => 'EARLY', 'role' => 'Reds:Ada'], '3' => ['shift' => 5, 'note' => 'LATE', 'role' => 'Reds:Linus']];

    #[Test]
    public function theRelatedEndpointRendersTheStoredAndComputedMembersOfAMixedPivot(): void
    {
        self::assertSame(self::STAFF, $this->pivots($this->get('/cp-crews/1/staff')));
    }

    #[Test]
    public function theRelationshipEndpointRendersTheStoredAndComputedMembersOfAMixedPivot(): void
    {
        self::assertSame(self::STAFF, $this->pivots($this->get('/cp-crews/1/relationships/staff')));
    }

    #[Test]
    public function anIncludedLinkageRendersTheStoredAndComputedMembersOfAMixedPivot(): void
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
