<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see ReadQueryConformanceTestCase} against the Doctrine provider: the same
 * assertions as the in-memory suite, executed as real DQL over an in-memory
 * SQLite database created per test and seeded through the
 * {@see ArticleEntityFactory} (Foundry). This is the Phase-1 functional slice —
 * filter/sort/pagination pushed down to a real QueryBuilder.
 */
final class DoctrineReadQueryTest extends ReadQueryConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
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
