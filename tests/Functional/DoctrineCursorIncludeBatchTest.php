<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\CursorWidgetFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorShelfEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see CursorIncludeBatchConformanceTestCase} against the Doctrine provider: the
 * same assertions as the in-memory witness, with each parent's included cursor page
 * minted through the per-parent keyset push-down inside the RelationScope parent
 * scope. A divergence from the witness localizes to the Doctrine keyset execution
 * (bundle ADR 0063).
 */
final class DoctrineCursorIncludeBatchTest extends CursorIncludeBatchConformanceTestCase
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

        // Seed the widgets in fixture id order so the AUTO column assigns sequential
        // ids 1..N matching the shared fixtures, then the shelves per the membership map.
        foreach (CursorWidgetFixtures::data() as $row) {
            CursorWidgetEntityFactory::createOne([
                'category' => $row['category'],
                'priority' => $row['priority'],
                'releasedAt' => $row['releasedAt'] !== null ? new \DateTimeImmutable($row['releasedAt']) : null,
            ]);
        }

        foreach (CursorShelfFixtures::data() as $widgetIds) {
            $shelf = new CursorShelfEntity();
            foreach ($widgetIds as $widgetId) {
                $widget = $entityManager->find(CursorWidgetEntity::class, $widgetId);
                \assert($widget instanceof CursorWidgetEntity);
                $shelf->widgets->add($widget);
            }
            $entityManager->persist($shelf);
        }
        $entityManager->flush();

        $entityManager->clear();
    }
}
