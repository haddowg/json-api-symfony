<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta\TypedMetaDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta\TypedMetaSeed;

final class DoctrineTypedDocumentMetaTest extends TypedDocumentMetaConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return TypedMetaDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $seed = TypedMetaSeed::build();
        // The pinned card is written after the cards exist.
        $pinned = $seed['boards'][0]->pinned;
        $seed['boards'][0]->pinned = null;
        foreach ([...$seed['boards'], ...$seed['cards']] as $row) {
            $entityManager->persist($row);
        }
        $entityManager->flush();
        $seed['boards'][0]->pinned = $pinned;
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function bootInDebug(): void
    {
        static::ensureKernelShutdown();
        static::bootKernel(['debug' => true]);
        $this->afterBoot();
    }
}
