<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAdvertiser;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAgency;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingMember;

/**
 * {@see IncludeScopingWriteConformanceTestCase} on the in-memory provider, with an
 * application provider for the related types hiding the rows. Each test gets a fresh graph.
 */
final class IncludeScopingWriteInMemoryTest extends IncludeScopingWriteConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IncludeScopingInMemoryTestKernel::class;
    }

    protected function setUp(): void
    {
        ScopingFixtures::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ScopingFixtures::reset();
    }

    protected function storedPartnerIds(string $prefix, int $id): array
    {
        $advertiser = $this->stored($prefix . 'advertisers', $id);
        \assert($advertiser instanceof ScopingAdvertiser);

        $ids = [];
        foreach ($advertiser->partners as $agency) {
            $ids[] = $agency->id;
        }
        \sort($ids);

        return $ids;
    }

    protected function storedTeamId(string $prefix, int $member): ?int
    {
        $stored = $this->stored($prefix . 'members', $member);
        \assert($stored instanceof ScopingMember);

        return $stored->team?->id;
    }

    protected function storedManagerId(string $prefix, int $agency): ?int
    {
        $stored = $this->stored($prefix . 'agencies', $agency);
        \assert($stored instanceof ScopingAgency);

        return $stored->manager?->id;
    }

    protected function storedMemberName(string $prefix, int $member): string
    {
        $stored = $this->stored($prefix . 'members', $member);
        \assert($stored instanceof ScopingMember);

        return $stored->name;
    }

    protected function storedMemberCount(string $prefix): int
    {
        return \count(ScopingFixtures::inMemoryProvider($prefix . 'members')->store()->all());
    }

    protected function storedAdvertiserName(string $prefix, int $id): string
    {
        $advertiser = $this->stored($prefix . 'advertisers', $id);
        \assert($advertiser instanceof ScopingAdvertiser);

        return $advertiser->name;
    }

    private function stored(string $type, int $id): object
    {
        $stored = ScopingFixtures::inMemoryProvider($type)->store()->find((string) $id);
        self::assertNotNull($stored);

        return $stored;
    }
}
