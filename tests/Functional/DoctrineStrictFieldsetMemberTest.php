<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\LeafletEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\StickerEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\StrictFieldsetDoctrineTestKernel;

/**
 * {@see StrictFieldsetMemberConformanceTestCase} against the Doctrine provider: the
 * same assertions as the in-memory suite, executed over an in-memory SQLite database
 * created per test and seeded through the Foundry factories. The strict member check
 * runs up front (before dispatch), so an unknown `fields[type]` member is rejected
 * before any DQL is built — identically to the in-memory witness.
 */
final class DoctrineStrictFieldsetMemberTest extends StrictFieldsetMemberConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return StrictFieldsetDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // No explicit ids: each entity's store-provided `AUTO` column assigns a
        // sequential int in insertion order, so the sticker gets 1 and the leaflet 1.
        $star = StickerEntityFactory::createOne(['label' => 'Star']);

        LeafletEntityFactory::createOne([
            'title' => 'Spring',
            'secret' => 'topsecret',
            'internalRef' => 'REF-1',
            'sticker' => $star,
        ]);

        $entityManager->clear();
    }
}
