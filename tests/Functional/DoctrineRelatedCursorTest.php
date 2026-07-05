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
 * {@see RelatedCursorConformanceTestCase} against the Doctrine provider: the
 * same assertions as the in-memory witness, executed as real DQL — the forced
 * NULL=largest ORDER BY + the IS-NULL-branched keyset WHERE composed with the
 * RelationScope IN-subquery parent predicate (the shelf's owning-side
 * ManyToMany) — over an in-memory SQLite database. A divergence from the
 * witness localizes to the Doctrine keyset push-down or its parent scoping
 * (bundle ADR 0063).
 */
final class DoctrineRelatedCursorTest extends RelatedCursorConformanceTestCase
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

        // Seed the widgets in fixture id order so the store-provided AUTO column
        // assigns sequential ids 1..N matching the shared fixtures.
        foreach (CursorWidgetFixtures::data() as $row) {
            CursorWidgetEntityFactory::createOne([
                'category' => $row['category'],
                'priority' => $row['priority'],
                'releasedAt' => $row['releasedAt'] !== null ? new \DateTimeImmutable($row['releasedAt']) : null,
            ]);
        }

        // Then the shelves, associating the seeded widget rows per the shared
        // membership map (the join-table rows the IN-subquery scope walks).
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
