<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PaginatorEventDoctrineTestKernel;

/**
 * {@see CollectionEventPaginatorConformanceTestCase} against the Doctrine provider,
 * over an in-memory SQLite database created per test and seeded with the canonical
 * fixtures through the {@see ArticleEntityFactory} (Foundry).
 */
final class DoctrineCollectionEventPaginatorTest extends CollectionEventPaginatorConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return PaginatorEventDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        // The in-memory SQLite database is empty per connection: create the
        // schema, then seed the canonical rows through the Foundry factory.
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // No explicit id: the store-provided `AUTO` column assigns sequential ints
        // in insertion order, so the fixtures' canonical id order (1..N) holds
        // against the freshly recreated schema.
        ArticleEntityFactory::createSequence(\array_values(ArticleFixtures::data()));

        $entityManager->clear();
    }
}
