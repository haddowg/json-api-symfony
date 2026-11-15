<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxInMemoryTestKernel;

/**
 * {@see WriteTransactionConformanceTestCase} on the in-memory persister, whose transaction
 * is a snapshot of both stores taken through one coordinator.
 */
final class InMemoryWriteTransactionTest extends WriteTransactionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return TxInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        TxFactory::reset();
    }
}
