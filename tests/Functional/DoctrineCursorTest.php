<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\CursorWidgetFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see CursorConformanceTestCase} against the Doctrine provider: the same
 * assertions as the in-memory witness, executed as real DQL (the forced
 * NULL=largest ORDER BY + the IS-NULL-branched keyset WHERE) over an in-memory
 * SQLite database seeded through the {@see CursorWidgetEntityFactory}. A
 * divergence from the witness localizes to the Doctrine keyset push-down
 * (bundle ADR 0063).
 */
final class DoctrineCursorTest extends CursorConformanceTestCase
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

        // Seed in fixture id order so the store-provided AUTO column assigns
        // sequential ids 1..N matching the shared fixtures.
        foreach (CursorWidgetFixtures::data() as $row) {
            CursorWidgetEntityFactory::createOne([
                'category' => $row['category'],
                'priority' => $row['priority'],
                'releasedAt' => $row['releasedAt'] !== null ? new \DateTimeImmutable($row['releasedAt']) : null,
            ]);
        }

        $entityManager->clear();
    }
}
