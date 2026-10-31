<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

final class MtmHiddenInMemoryTestKernel extends MtmInMemoryTestKernel
{
    protected function name(): string
    {
        return 'identified-morph-many-inmemory-hidden';
    }

    protected function scoped(): bool
    {
        return true;
    }
}
