<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\GateFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\GateInMemoryTestKernel;

/**
 * {@see GateOrderConformanceTestCase} on the in-memory provider and persister.
 */
final class InMemoryGateOrderTest extends GateOrderConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return GateInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        GateFactory::reset();
    }
}
