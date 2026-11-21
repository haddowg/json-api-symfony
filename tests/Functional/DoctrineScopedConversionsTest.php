<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions\ScDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions\ScFixtures;

final class DoctrineScopedConversionsTest extends ScopedConversionsTestCase
{
    protected static function getKernelClass(): string
    {
        return ScDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        foreach (ScFixtures::journals() as $journal) {
            $entityManager->persist($journal);
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
