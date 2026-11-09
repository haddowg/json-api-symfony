<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpFixtures;
use PHPUnit\Framework\Attributes\Test;

/**
 * The computed-only `members` conformance on Doctrine, plus `staff`, which mixes the
 * stored `shift` column of its association entity with a computed `role`.
 */
final class DoctrineComputedPivotTest extends ComputedPivotConformanceTestCase
{
    private const array STAFF = ['1' => ['shift' => 2, 'role' => 'Reds:Ada'], '2' => ['shift' => 5, 'role' => 'Reds:Grace']];

    protected static function getKernelClass(): string
    {
        return CpDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (CpFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aMixedPivotRendersStoredAndComputedFieldsOnTheRelatedEndpoint(): void
    {
        self::assertSame(self::STAFF, $this->pivots($this->get('/cp-crews/1/staff')));
    }

    #[Test]
    public function aMixedPivotRendersStoredAndComputedFieldsOnTheRelationshipEndpoint(): void
    {
        self::assertSame(self::STAFF, $this->pivots($this->get('/cp-crews/1/relationships/staff')));
    }

    #[Test]
    public function aMixedPivotRendersStoredAndComputedFieldsOnAnIncludedLinkage(): void
    {
        $document = $this->get('/cp-crews/1?include=staff');

        self::assertSame(self::STAFF, $this->pivots($this->linkage($document, 'staff')));
    }

    #[Test]
    public function aMixedPivotSortsByItsStoredFieldOnly(): void
    {
        self::assertSame(200, $this->handle('/cp-crews/1/staff?sort=-shift')->getStatusCode());
        self::assertSame(400, $this->handle('/cp-crews/1/staff?sort=role')->getStatusCode());
    }
}
