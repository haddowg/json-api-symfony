<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmHiddenInMemoryTestKernel;

final class InMemoryHiddenIdentifiedMorphToManyTest extends InMemoryIdentifiedMorphToManyTest
{
    protected static function getKernelClass(): string
    {
        return MtmHiddenInMemoryTestKernel::class;
    }

    protected function hides(): bool
    {
        return true;
    }
}
