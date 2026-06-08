<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WritableInMemoryTestKernel;

/**
 * {@see WriteConformanceTestCase} against the in-memory persister: the witness
 * for the Doctrine write path, running zero-database create/update/delete through
 * the shared {@see \haddowg\JsonApiBundle\DataProvider\InMemoryStore}.
 */
final class InMemoryWriteTest extends WriteConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return WritableInMemoryTestKernel::class;
    }
}
