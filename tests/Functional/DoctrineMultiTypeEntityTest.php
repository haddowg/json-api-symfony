<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\MemberEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\MultiTypeDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PostEntityFactory;

/**
 * {@see MultiTypeEntityConformanceTestCase} against the Doctrine provider: the same
 * assertions as the in-memory suite, over an in-memory SQLite database created per
 * test and seeded through the Foundry factories. Both the `members` and
 * `public-members` types map to {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\MemberEntity}
 * via `#[AsJsonApiResource(entity: …)]`, so each route resolves the same row through
 * the one Doctrine provider — proving the type→entity map tolerates two types → one
 * entity.
 */
final class DoctrineMultiTypeEntityTest extends MultiTypeEntityConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MultiTypeDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Members 1 (Ada, private email/secretNote) and 2 (Bob) — store-provided ids in
        // insertion order. Post 1 authored by Ada; post 2 with no author.
        $ada = MemberEntityFactory::createOne([
            'displayName' => 'Ada',
            'email' => 'ada@example.test',
            'secretNote' => 'launch codes',
        ]);
        MemberEntityFactory::createOne([
            'displayName' => 'Bob',
            'email' => 'bob@example.test',
            'secretNote' => 'gone fishing',
        ]);

        PostEntityFactory::createOne(['title' => 'Hello', 'author' => $ada]);
        PostEntityFactory::createOne(['title' => 'Draft', 'author' => null]);

        $entityManager->clear();
    }
}
