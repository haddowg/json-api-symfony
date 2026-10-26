<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillFile;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillUsingDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillUsingFixtures;

final class DoctrineFillUsingTest extends FillUsingConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FillUsingDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (FillUsingFixtures::graph() as $items) {
            foreach ($items as $item) {
                $entityManager->persist($item);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function storedTarget(int $file): array
    {
        $stored = $this->file($file);

        return [$stored?->targetType, $stored?->targetId];
    }

    protected function storedWatchers(int $file): array
    {
        return $this->file($file)->watcherIds ?? [];
    }

    protected function storedFileCount(): int
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(FillFile::class)->count([]);
    }

    private function file(int $id): ?FillFile
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(FillFile::class, $id);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
