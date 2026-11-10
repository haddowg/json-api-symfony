<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\FixedMtmInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmUser;

class InMemoryFixedMembershipMorphTest extends FixedMembershipMorphConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FixedMtmInMemoryTestKernel::class;
    }

    protected function storedCompanies(int $user): array
    {
        $fixtures = static::getContainer()->get(MtmFixtures::class);
        \assert($fixtures instanceof MtmFixtures);
        $stored = $fixtures->provider('users')->store()->find((string) $user);

        return $stored instanceof MtmUser ? $stored->companyKeys() : [];
    }
}
