<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

final class CmScopedInMemoryTestKernel extends CmInMemoryTestKernel
{
    protected function name(): string
    {
        return 'computed-morph-inmemory-scoped';
    }

    protected function scoped(): bool
    {
        return true;
    }
}
