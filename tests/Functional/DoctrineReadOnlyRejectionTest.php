<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyRejection\Memo;
use haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyRejection\ReadOnlyRejectionDoctrineTestKernel;

final class DoctrineReadOnlyRejectionTest extends ReadOnlyRejectionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ReadOnlyRejectionDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $memo = new Memo();
        $memo->body = 'Seeded';
        $entityManager->persist($memo);
        $entityManager->flush();
        $entityManager->clear();
    }
}
