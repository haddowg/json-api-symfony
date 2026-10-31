<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmHiddenDoctrineTestKernel;

final class DoctrineHiddenIdentifiedMorphToManyTest extends DoctrineIdentifiedMorphToManyTest
{
    protected static function getKernelClass(): string
    {
        return MtmHiddenDoctrineTestKernel::class;
    }

    protected function hides(): bool
    {
        return true;
    }
}
