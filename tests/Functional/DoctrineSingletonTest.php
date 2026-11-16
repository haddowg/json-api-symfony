<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Singleton\Inbox;
use haddowg\JsonApiBundle\Tests\Functional\App\Singleton\InboxItem;
use haddowg\JsonApiBundle\Tests\Functional\App\Singleton\SingletonDoctrineTestKernel;

/**
 * {@see SingletonConformanceTestCase} on Doctrine.
 */
final class DoctrineSingletonTest extends SingletonConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return SingletonDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $ada = new Inbox(1, 'ada', 'Ada', 3);
        $grace = new Inbox(2, 'grace', 'Grace', 1);
        $first = new InboxItem(1, 'Hello', $ada);
        $second = new InboxItem(2, 'Again', $ada);
        $third = new InboxItem(3, 'Private', $grace);
        foreach ([$ada, $grace, $first, $second, $third] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $ada->pinned = $second;
        $entityManager->flush();
        $entityManager->clear();
    }
}
