<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;

/**
 * Shared `afterBoot()` for the Doctrine write/validation suites: creates the
 * in-memory SQLite schema (it lives and dies with the kernel's connection) and
 * seeds the canonical {@see ArticleFixtures} rows through the Foundry factory.
 */
trait SeedsDoctrineArticles
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // No explicit id: the store-provided `AUTO` column assigns sequential ints
        // in insertion order, so the fixtures' canonical id order (1..N) holds
        // against the freshly recreated schema.
        ArticleEntityFactory::createSequence(\array_values(ArticleFixtures::data()));

        $entityManager->clear();
    }
}
