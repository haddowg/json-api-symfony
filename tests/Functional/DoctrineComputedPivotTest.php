<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpShift;
use PHPUnit\Framework\Attributes\Test;

/**
 * The computed-only `members` conformance on Doctrine, plus `staff`, which mixes the
 * stored `shift` column of its association entity, a stored `note` read through an
 * extract hook, and a computed `role`.
 */
final class DoctrineComputedPivotTest extends ComputedPivotConformanceTestCase
{
    private const array STAFF = ['1' => ['shift' => 2, 'note' => 'EARLY', 'role' => 'Reds:Ada'], '2' => ['shift' => 5, 'note' => 'LATE', 'role' => 'Reds:Grace']];

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

    #[Test]
    public function aStoredFieldWithAnExtractHookIsWrittenOnUpdateAndReadThroughItsHook(): void
    {
        $response = $this->handle('/cp-crews/1/relationships/staff', 'PATCH', ['data' => [
            ['type' => 'cp-members', 'id' => '1', 'meta' => ['pivot' => ['shift' => 3, 'note' => 'night', 'role' => 'ignored']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1' => ['shift' => 3, 'note' => 'NIGHT', 'role' => 'Reds:Ada']], $this->pivots($this->get('/cp-crews/1/relationships/staff')));
        self::assertSame(['night'], $this->storedNotes('1'));
    }

    #[Test]
    public function aStoredFieldWithAnExtractHookIsWrittenOnCreateAndReadThroughItsHook(): void
    {
        $response = $this->handle('/cp-crews', 'POST', ['data' => [
            'type' => 'cp-crews',
            'id' => '9',
            'attributes' => ['name' => 'Blues'],
            'relationships' => ['staff' => ['data' => [['type' => 'cp-members', 'id' => '2', 'meta' => ['pivot' => ['shift' => 7, 'note' => 'dawn']]]]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['2' => ['shift' => 7, 'note' => 'DAWN', 'role' => 'Blues:Grace']], $this->pivots($this->get('/cp-crews/9/staff')));
        self::assertSame(['dawn'], $this->storedNotes('9'));
    }

    /**
     * The `note` column the association entity holds for each of a crew's shifts.
     *
     * @return list<string|null>
     */
    private function storedNotes(string $crewId): array
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();

        $notes = [];
        foreach ($entityManager->getRepository(CpShift::class)->findBy(['crew' => $crewId]) as $shift) {
            $notes[] = $shift->note;
        }

        return $notes;
    }
}
