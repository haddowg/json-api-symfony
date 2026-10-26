<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone\OutputTimezoneDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see OutputTimezoneConformanceTestCase} against the Doctrine provider, plus the
 * pivot meta the Doctrine provider alone renders. The provider casts each pivot
 * column while it reads the association rows, so the server zone has to reach it
 * separately from the resource attributes (bundle ADR 0123).
 */
final class DoctrineOutputTimezoneTest extends OutputTimezoneConformanceTestCase
{
    use SeedsDoctrineOutputTimezone;

    protected static function getKernelClass(): string
    {
        return OutputTimezoneDoctrineTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function pivotMetaOnTheRelatedEndpointFollowsTheServerZone(): void
    {
        $data = $this->fetchDocument('/gigs/1/performers')['data'] ?? null;
        self::assertIsArray($data);
        $member = $data[0] ?? null;
        self::assertIsArray($member);

        self::assertSame(self::EXPECTED_PIVOT, $this->pivotOf($member));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function pivotMetaOnTheRelationshipEndpointFollowsTheServerZone(): void
    {
        $data = $this->fetchDocument('/gigs/1/relationships/performers')['data'] ?? null;
        self::assertIsArray($data);
        $identifier = $data[0] ?? null;
        self::assertIsArray($identifier);

        self::assertSame(self::EXPECTED_PIVOT, $this->pivotOf($identifier));
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function pivotMetaOnAPrimaryDocumentLinkageFollowsTheServerZone(): void
    {
        $data = $this->fetchDocument('/gigs/1')['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $booked = $relationships['bookedPerformers'] ?? null;
        self::assertIsArray($booked);
        $linkage = $booked['data'] ?? null;
        self::assertIsArray($linkage);
        $identifier = $linkage[0] ?? null;
        self::assertIsArray($identifier);

        self::assertSame(self::EXPECTED_PIVOT, $this->pivotOf($identifier));
    }

    /**
     * `bookedAt` takes UTC, `confirmedAt` keeps its own New York zone, and the
     * `contractOn` date stays on the Paris day (UTC would make it 2024-04-02).
     */
    private const array EXPECTED_PIVOT = [
        'bookedAt' => '2024-04-01T10:00:00+00:00',
        'confirmedAt' => '2024-04-02T06:00:00-04:00',
        'contractOn' => '2024-04-03',
    ];

    /**
     * @param array<mixed> $object
     *
     * @return array<mixed>
     */
    private function pivotOf(array $object): array
    {
        $meta = $object['meta'] ?? null;
        self::assertIsArray($meta);
        $pivot = $meta['pivot'] ?? null;
        self::assertIsArray($pivot);

        return $pivot;
    }
}
