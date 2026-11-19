<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\FieldSelectionFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\FieldSelectionInMemoryTestKernel;

final class InMemoryFieldSelectionTest extends FieldSelectionConformanceTestCase
{
    protected function afterBoot(): void
    {
        FieldSelectionFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return FieldSelectionInMemoryTestKernel::class;
    }
}
