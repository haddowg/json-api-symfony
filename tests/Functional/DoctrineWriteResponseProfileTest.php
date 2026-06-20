<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see WriteResponseProfileConformanceTestCase} against the Doctrine provider: the
 * read+write kernel over an in-memory SQLite database seeded with the full
 * `articles`/`authors`/`comments` graph ({@see SeedsDoctrineRelationships}), so a
 * PATCH/POST response renders the relationship windowing / counting seams over real
 * persisted associations — the Doctrine twin of the in-memory witness.
 */
final class DoctrineWriteResponseProfileTest extends WriteResponseProfileConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
