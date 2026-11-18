<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

/**
 * {@see ForeignKeyDoctrineTestKernel} with window functions off, so each parent's page
 * is its own bounded query.
 */
final class ForeignKeyPerParentTestKernel extends ForeignKeyDoctrineTestKernel
{
    protected function name(): string
    {
        return 'foreign-key-per-parent';
    }

    protected function windowFunctions(): bool
    {
        return false;
    }
}
