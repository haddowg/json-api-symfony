<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\CursorWidgetFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorShelfEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorShelfWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntityFactory;

/**
 * Seeds the Doctrine cursor-shelf fixture for the pivot/linkage cursor suites:
 * the shared {@see CursorWidgetFixtures} widgets, the {@see CursorShelfFixtures}
 * shelves with their `widgets` join-table membership, AND one
 * {@see CursorShelfWidgetEntity} association row per membership carrying the
 * shared {@see CursorShelfFixtures::slots()} pivot value — so the `pivotWidgets`
 * pivot keyset pages assert against the same content the in-memory plain keyset
 * pages walk (bundle ADR 0114).
 */
trait SeedsDoctrineCursorShelves
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Seed the widgets in fixture id order so the store-provided AUTO column
        // assigns sequential ids 1..N matching the shared fixtures.
        foreach (CursorWidgetFixtures::data() as $row) {
            CursorWidgetEntityFactory::createOne([
                'category' => $row['category'],
                'priority' => $row['priority'],
                'releasedAt' => $row['releasedAt'] !== null ? new \DateTimeImmutable($row['releasedAt']) : null,
            ]);
        }

        // Then the shelves: the plain `widgets` join-table membership PLUS one
        // association-entity row per membership carrying the shared `slot` pivot
        // value the `pivotWidgets` relation reads.
        $slots = CursorShelfFixtures::slots();
        foreach (CursorShelfFixtures::data() as $widgetIds) {
            $shelf = new CursorShelfEntity();
            $entityManager->persist($shelf);
            foreach ($widgetIds as $widgetId) {
                $widget = $entityManager->find(CursorWidgetEntity::class, $widgetId);
                \assert($widget instanceof CursorWidgetEntity);
                $shelf->widgets->add($widget);
                $entityManager->persist(new CursorShelfWidgetEntity(
                    shelf: $shelf,
                    widget: $widget,
                    slot: $slots[$widgetId],
                ));
            }
        }
        $entityManager->flush();

        $entityManager->clear();
    }
}
