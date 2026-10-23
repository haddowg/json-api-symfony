<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAdvertiser;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAgency;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingMember;

/**
 * Seeds the include-scoping graph into the Doctrine kernel and reads stored state back
 * past every scope. Both servers' types map the same tables.
 */
trait IncludeScopingDoctrineWrites
{
    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ScopingFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @return list<int>
     */
    protected function storedPartnerIds(string $prefix, int $id): array
    {
        $ids = [];
        foreach ($this->find(ScopingAdvertiser::class, $id)->partners as $agency) {
            $ids[] = $agency->id;
        }
        \sort($ids);

        return $ids;
    }

    protected function storedTeamId(string $prefix, int $member): ?int
    {
        return $this->find(ScopingMember::class, $member)->team?->id;
    }

    protected function storedManagerId(string $prefix, int $agency): ?int
    {
        return $this->find(ScopingAgency::class, $agency)->manager?->id;
    }

    protected function storedMemberName(string $prefix, int $member): string
    {
        return $this->find(ScopingMember::class, $member)->name;
    }

    protected function storedMemberCount(string $prefix): int
    {
        return $this->entityManager()->getRepository(ScopingMember::class)->count([]);
    }

    protected function storedAdvertiserName(string $prefix, int $id): string
    {
        return $this->find(ScopingAdvertiser::class, $id)->name;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, int $id): object
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $entity = $entityManager->find($class, $id);
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
