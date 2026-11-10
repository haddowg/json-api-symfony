<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage\LinkageFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage\LoadedLinkageDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Test;

final class LoadedLinkageDoctrineTest extends LoadedLinkageConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return LoadedLinkageDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (LinkageFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * A lazy to-many renders the same links-only relationship whether or not the
     * attribute loaded its association first.
     */
    #[Test]
    public function aLazyToManyLoadedAfterThePreloadStaysLinksOnly(): void
    {
        $document = $this->get('/templates/1');

        foreach (['bindings', 'sharedBindings'] as $name) {
            $relationship = $this->relationship($document, '1', $name);
            self::assertArrayNotHasKey('data', $relationship, $name);
            self::assertArrayHasKey('links', $relationship, $name);
        }
    }
}
