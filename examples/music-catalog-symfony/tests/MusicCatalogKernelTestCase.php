<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\Seed;
use haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel;
use haddowg\JsonApiBundle\Tests\Functional\JsonApiFunctionalTestCase;

/**
 * The base test case for the music-catalog example suites. It extends the bundle's
 * own {@see JsonApiFunctionalTestCase} (inheriting `handle()`/`decode()` and the
 * error/exception-handler-stack restore), names the example app's
 * {@see MusicCatalogKernel}, and in `afterBoot()` creates the in-memory SQLite
 * schema and loads the deterministic {@see Seed} for all seven entity types — so
 * every example suite boots against a fully populated database.
 *
 * The in-memory database lives and dies with the kernel's connection, so the
 * schema + seed are recreated per test.
 */
abstract class MusicCatalogKernelTestCase extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return MusicCatalogKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        Seed::into($entityManager);
    }
}
