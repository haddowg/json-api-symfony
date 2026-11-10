<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\FixedMtmHiddenInMemoryTestKernel;

final class InMemoryHiddenFixedMembershipMorphTest extends InMemoryFixedMembershipMorphTest
{
    protected static function getKernelClass(): string
    {
        return FixedMtmHiddenInMemoryTestKernel::class;
    }

    protected function hides(): bool
    {
        return true;
    }
}
