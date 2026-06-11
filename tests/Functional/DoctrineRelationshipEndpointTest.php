<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see RelationshipEndpointConformanceTestCase} against the Doctrine provider:
 * the same related/relationship-endpoint and compound-document assertions,
 * executed as real DQL over an in-memory SQLite database seeded per test through
 * the Foundry factories with the author/comment associations wired
 * ({@see SeedsDoctrineRelationships}).
 */
final class DoctrineRelationshipEndpointTest extends RelationshipEndpointConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
