<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see WriteConformanceTestCase} against the Doctrine persister: the same
 * create/update/delete assertions as the in-memory witness, executed as real
 * `persist`/`flush`/`remove` over an in-memory SQLite database created and seeded
 * per test. The Doctrine read provider and write persister are both the `-128`
 * fallbacks the {@see DoctrineJsonApiTestKernel} wires from the resource's
 * `#[AsJsonApiResource(entity: …)]` mapping — no kernel change beyond the seed.
 */
final class DoctrineWriteTest extends WriteConformanceTestCase
{
    use SeedsDoctrineArticles;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
