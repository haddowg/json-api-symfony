<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TrNote;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TypedRequestMetaDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TypedRequestMetaSeed;

final class DoctrineTypedRequestMetaTest extends TypedRequestMetaConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return TypedRequestMetaDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $seed = TypedRequestMetaSeed::build();
        foreach ([...$seed['items'], ...$seed['notes'], ...$seed['plains']] as $row) {
            $entityManager->persist($row);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function bootInDebug(): void
    {
        static::ensureKernelShutdown();
        static::bootKernel(['debug' => true]);
        $this->afterBoot();
    }

    protected function sourceOf(string $id): ?string
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return $entityManager->find(TrNote::class, (int) $id)?->source;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
