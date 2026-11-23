<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OptionNamedFields\OptionNamedFieldsInMemoryTestKernel;

final class InMemoryOptionNamedFieldsTest extends OptionNamedFieldsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OptionNamedFieldsInMemoryTestKernel::class;
    }
}
