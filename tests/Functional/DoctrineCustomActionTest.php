<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine\ActionDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine\WidgetEntityFactory;

/**
 * {@see CustomActionConformanceTestCase} against the reference Doctrine
 * provider/persister: the same §10 action matrix as the in-memory witness, executed
 * over an in-memory SQLite database created and seeded per test. Two `actionWidgets`
 * rows (ids 1, 2) are seeded so the resource-scope cases resolve their entity.
 */
final class DoctrineCustomActionTest extends CustomActionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Two widgets in insertion order → store-provided ids 1, 2.
        WidgetEntityFactory::createOne(['name' => 'First widget']);
        WidgetEntityFactory::createOne(['name' => 'Second widget']);

        $entityManager->clear();
    }
}
