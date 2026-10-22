<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OutputTimezoneInMemoryTestKernel;

/**
 * {@see OutputTimezoneConformanceTestCase} against the in-memory provider, whose
 * fixtures hold Europe/Paris `\DateTimeImmutable` values.
 */
final class InMemoryOutputTimezoneTest extends OutputTimezoneConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OutputTimezoneInMemoryTestKernel::class;
    }
}
