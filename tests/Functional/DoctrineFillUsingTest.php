<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDataPersister;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillFile;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillUsingDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillUsingFixtures;
use PHPUnit\Framework\Attributes\Test;

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

    protected function storedSetting(int $file): ?string
    {
        return $this->file($file)?->settingId;
    }

    protected function storedSettings(int $file): array
    {
        return $this->file($file)->settingIds ?? [];
    }

    protected function storedFileCount(): int
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(FillFile::class)->count([]);
    }

    #[Test]
    public function thePersisterSaysAnInverseToManyOverAStringKeyCanMissItsLinkage(): void
    {
        $persister = static::getContainer()->get(DoctrineDataPersister::class);
        self::assertInstanceOf(DoctrineDataPersister::class, $persister);
        $slots = HasMany::make('slots', 'fill-slots')->build();

        // A string key converts from any id, so only loading the member to set its owner
        // can find it missing.
        self::assertFalse($persister->canMissLinkage($slots));
        self::assertTrue($persister->canMissLinkage($slots, 'fill-shelves'));
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
