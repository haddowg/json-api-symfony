<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
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

        ArticleEntityFactory::createSequence(
            \array_map(
                static fn(int|string $id, array $article): array => ['id' => (string) $id, ...$article],
                \array_keys(ArticleFixtures::data()),
                \array_values(ArticleFixtures::data()),
            ),
        );

        $entityManager->clear();
    }
}
