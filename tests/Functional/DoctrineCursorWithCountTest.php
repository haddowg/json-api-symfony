<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see CursorWithCountConformanceTestCase} against the Doctrine provider on sqlite.
 */
final class DoctrineCursorWithCountTest extends CursorWithCountConformanceTestCase
{
    use SeedsDoctrineCursorShelves;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
