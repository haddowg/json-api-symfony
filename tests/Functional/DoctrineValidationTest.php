<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see ValidationConformanceTestCase} against the Doctrine kernel: the bridge
 * runs the same constraint translation and `422`/pointer rendering over the
 * Doctrine-sqlite write path.
 */
final class DoctrineValidationTest extends ValidationConformanceTestCase
{
    use SeedsDoctrineArticles;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
