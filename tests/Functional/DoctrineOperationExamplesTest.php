<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples\Note;
use haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples\OperationExamplesDoctrineTestKernel;

final class DoctrineOperationExamplesTest extends OperationExamplesConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OperationExamplesDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $note = new Note();
        $note->title = 'Seeded';
        $note->rating = 3;
        $entityManager->persist($note);
        $entityManager->flush();
        $entityManager->clear();
    }
}
