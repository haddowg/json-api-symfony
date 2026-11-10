<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmUser;

class InMemoryIdentifiedMorphToManyTest extends IdentifiedMorphToManyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MtmInMemoryTestKernel::class;
    }

    protected function hides(): bool
    {
        return false;
    }

    protected function storedCompanies(int $user): array
    {
        return $this->user($user)?->companyKeys() ?? [];
    }

    protected function storeCompany(int $user, string $type, int $id): void
    {
        $this->user($user)?->addCompany($type, $id);
    }

    protected function storedPositions(int $user): array
    {
        $positions = [];
        foreach ($this->user($user)?->userCompanies->toArray() ?? [] as $row) {
            $positions[$row->key()] = $row->position;
        }
        \asort($positions);

        return $positions;
    }

    private function user(int $id): ?MtmUser
    {
        $fixtures = static::getContainer()->get(MtmFixtures::class);
        \assert($fixtures instanceof MtmFixtures);
        $stored = $fixtures->provider('users')->store()->find((string) $id);

        return $stored instanceof MtmUser ? $stored : null;
    }
}
