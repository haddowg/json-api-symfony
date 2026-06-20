<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\IdentifierMetaDoctrineTestKernel;

/**
 * {@see IdentifierMetaConformanceTestCase} against the Doctrine provider: the
 * `identifierMeta()` resolvers read the related managed entities the provider
 * loaded for the linkage (core ADR 0084). The SQLite schema is created and seeded
 * by {@see SeedsDoctrineRelationships}.
 */
final class DoctrineIdentifierMetaTest extends IdentifierMetaConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return IdentifierMetaDoctrineTestKernel::class;
    }
}
