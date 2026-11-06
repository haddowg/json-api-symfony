<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Deprecation\Bulletin;
use haddowg\JsonApiBundle\Tests\Functional\App\Deprecation\DeprecationDoctrineTestKernel;

final class DoctrineDeprecationTest extends DeprecationConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DeprecationDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $first = new Bulletin(title: 'First', code: 'A1');
        $second = new Bulletin(title: 'Second', code: 'B2');
        $second->parent = $first;
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();
        $entityManager->clear();
    }
}
