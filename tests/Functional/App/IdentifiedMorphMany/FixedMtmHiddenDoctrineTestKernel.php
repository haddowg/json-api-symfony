<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

final class FixedMtmHiddenDoctrineTestKernel extends FixedMtmDoctrineTestKernel
{
    protected function name(): string
    {
        return 'fixed-membership-morph-many-doctrine-hidden';
    }

    protected function scoped(): bool
    {
        return true;
    }
}
