<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph\IdentifiedCampaign;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph\IdentifiedMorphFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph\IdentifiedMorphInMemoryTestKernel;

/**
 * {@see IdentifiedMorphToConformanceTestCase} against the in-memory provider.
 */
final class InMemoryIdentifiedMorphToTest extends IdentifiedMorphToConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdentifiedMorphInMemoryTestKernel::class;
    }

    protected function setUp(): void
    {
        IdentifiedMorphFixtures::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        IdentifiedMorphFixtures::reset();
    }

    protected function storeTarget(int $campaignId, string $type, int $id): void
    {
        $campaign = IdentifiedMorphFixtures::inMemoryProvider('campaigns')->store()->find((string) $campaignId);
        self::assertInstanceOf(IdentifiedCampaign::class, $campaign);
        $campaign->targetType = $type;
        $campaign->targetId = $id;
    }
}
