<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

final class FixedMtmHiddenInMemoryTestKernel extends FixedMtmInMemoryTestKernel
{
    protected function name(): string
    {
        return 'fixed-membership-morph-many-inmemory-hidden';
    }

    protected function scoped(): bool
    {
        return true;
    }
}
