<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see OpenApiConformanceTestCase} against the **Doctrine** provider: the same
 * generated-schema round-trip assertions, executed over real DQL against an in-memory
 * SQLite database seeded — authors → articles → comments, with the associations wired —
 * through the Foundry factories of the shared {@see App\ArticleFixtures}. Proves the one
 * generated document describes the Doctrine provider's responses identically to the
 * in-memory provider's.
 */
final class DoctrineOpenApiConformanceTest extends OpenApiConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
