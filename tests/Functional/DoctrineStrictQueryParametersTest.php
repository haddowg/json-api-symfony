<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see StrictQueryParametersConformanceTestCase} against the Doctrine provider:
 * the same assertions as the in-memory suite, executed over an in-memory SQLite
 * database created per test and seeded through the {@see ArticleEntityFactory}
 * (Foundry). Strict validation runs up front (before dispatch), so an unrecognized
 * family is rejected before any DQL is built.
 */
final class DoctrineStrictQueryParametersTest extends StrictQueryParametersConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        ArticleEntityFactory::createSequence(\array_values(ArticleFixtures::data()));

        $entityManager->clear();
    }
}
