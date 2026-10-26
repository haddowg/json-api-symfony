<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone\OutputTimezoneDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see DeclaredLinksConformanceTestCase} against the Doctrine provider, plus the pivot
 * reads the Doctrine provider alone serves. A pivot related member renders through the
 * bundle's pivot meta serializer, and a gig with pivot linkage through its pivot parent
 * serializer, and both have to pass the declared links through.
 */
final class DoctrineDeclaredLinksTest extends DeclaredLinksConformanceTestCase
{
    use SeedsDoctrineOutputTimezone;

    protected static function getKernelClass(): string
    {
        return OutputTimezoneDoctrineTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedMemberKeepsItsDeclaredLinks(): void
    {
        $member = $this->data('/gigs/1/performers')[0] ?? null;
        self::assertIsArray($member);

        self::assertSame('https://example.test/performers/1/profile', $this->linksOf($member)['profile'] ?? null);
        $meta = $member['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('pivot', $meta);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function aPrimaryResourceWithPivotLinkageKeepsItsDeclaredLinks(): void
    {
        self::assertSame('https://example.test/gigs/1/poster', $this->linksOf($this->data('/gigs/1'))['poster'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPivotIncludedMemberKeepsItsDeclaredLinks(): void
    {
        $included = $this->fetchDocument('/gigs/1?include=bookedPerformers')['included'] ?? null;
        self::assertIsArray($included);
        $performer = $included[0] ?? null;
        self::assertIsArray($performer);

        self::assertSame('https://example.test/performers/1/profile', $this->linksOf($performer)['profile'] ?? null);
    }
}
