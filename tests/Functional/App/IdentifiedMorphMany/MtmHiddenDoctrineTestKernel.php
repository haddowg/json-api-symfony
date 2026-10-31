<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

final class MtmHiddenDoctrineTestKernel extends MtmDoctrineTestKernel
{
    protected function name(): string
    {
        return 'identified-morph-many-doctrine-hidden';
    }

    protected function scoped(): bool
    {
        return true;
    }
}
