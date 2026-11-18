<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate\IdentifiedMorphGateDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate\IdentifiedMorphGateFixtures;

/**
 * {@see IdentifiedMorphToReadGateConformanceTestCase} against the Doctrine provider.
 */
final class DoctrineIdentifiedMorphToReadGateTest extends IdentifiedMorphToReadGateConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdentifiedMorphGateDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (IdentifiedMorphGateFixtures::graph() as $items) {
            foreach ($items as $item) {
                $entityManager->persist($item);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
