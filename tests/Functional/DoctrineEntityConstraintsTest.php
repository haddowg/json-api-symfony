<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints\Brand;
use haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints\EntityConstraintsDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * Adds the Doctrine-only UniqueEntity options to the shared suite: the name is unique
 * among the brands the repository method returns, which leaves out archived ones, and
 * the violation points at the error path. `Archived` is an archived brand, `Active` an
 * active one.
 */
final class DoctrineEntityConstraintsTest extends EntityConstraintsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EntityConstraintsDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (['Archived' => true, 'Active' => false] as $name => $archived) {
            $brand = new Brand();
            $brand->name = $name;
            $brand->archived = $archived;
            $entityManager->persist($brand);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function theNameOfAnArchivedBrandCanBeReused(): void
    {
        self::assertSame(201, $this->create(['name' => 'Archived'])->getStatusCode());
    }

    #[Test]
    public function theNameOfAnActiveBrandIsRejectedAtTheErrorPath(): void
    {
        $response = $this->create(['name' => 'Active']);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['/data/attributes/label', 'An active brand already has this name.']], $this->pointers($response));
    }
}
