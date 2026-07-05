<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CompositeDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CompositeWidgetEntity;

/**
 * {@see CompositeConformanceTestCase} against the Doctrine provider: each
 * composite attribute (`Obj` address, `OneOf` block, `ArrayHash`+`Shape`
 * contact) is a single `json` column on {@see CompositeWidgetEntity} in an
 * in-memory SQLite database — so the same assertions witness that a composite
 * value round-trips real column storage, not just the in-memory array.
 */
final class DoctrineCompositeTest extends CompositeConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CompositeDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        // The in-memory SQLite database is empty per connection: create the
        // schema, then seed the same widget the in-memory provider starts with
        // (the store-provided `AUTO` id assigns it 1, matching the fixture id).
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new CompositeWidgetEntity(
            name: 'Seed',
            address: ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1'],
        ));
        $entityManager->flush();
        $entityManager->clear();
    }
}
