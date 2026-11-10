<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\FixedMtmHiddenDoctrineTestKernel;

final class DoctrineHiddenFixedMembershipMorphTest extends DoctrineFixedMembershipMorphTest
{
    protected static function getKernelClass(): string
    {
        return FixedMtmHiddenDoctrineTestKernel::class;
    }

    protected function hides(): bool
    {
        return true;
    }
}
