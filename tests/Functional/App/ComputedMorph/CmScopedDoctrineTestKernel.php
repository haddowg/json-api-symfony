<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

final class CmScopedDoctrineTestKernel extends CmDoctrineTestKernel
{
    protected function name(): string
    {
        return 'computed-morph-doctrine-scoped';
    }

    protected function scoped(): bool
    {
        return true;
    }
}
