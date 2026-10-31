<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmUser;

class InMemoryComputedMorphToManyTest extends ComputedMorphToManyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CmInMemoryTestKernel::class;
    }

    protected function scoped(): bool
    {
        return false;
    }

    protected function storedCompanies(int $user): array
    {
        $fixtures = static::getContainer()->get(CmFixtures::class);
        \assert($fixtures instanceof CmFixtures);
        $stored = $fixtures->provider('cm-users')->store()->find((string) $user);

        return $stored instanceof CmUser ? $stored->companyKeys() : [];
    }
}
