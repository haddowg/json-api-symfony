<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The nested pivot include conformance on Doctrine, plus `staff`, whose stored `shift` and
 * `note` columns come from its association entity, read once per include level.
 */
final class DoctrineNestedPivotIncludeTest extends NestedPivotIncludeConformanceTestCase
{
    private const array REDS_STAFF = ['1' => ['shift' => 2, 'note' => 'EARLY', 'role' => 'Reds:Ada'], '2' => ['shift' => 5, 'note' => 'LATE', 'role' => 'Reds:Grace']];
    private const array BLUES_STAFF = ['3' => ['shift' => 4, 'note' => null, 'role' => 'Blues:Linus']];

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
    #[Group('spec:fetching-includes')]
    public function aStoredPivotReachedThroughANestedIncludeRendersItsColumns(): void
    {
        $document = $this->get('/cp-ships?include=crew.staff');

        self::assertSame(self::REDS_STAFF, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'staff')));
        self::assertSame(self::BLUES_STAFF, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'staff')));
        self::assertSame(self::REDS_STAFF + self::BLUES_STAFF, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aStoredPivotTwoIncludesDeepRendersItsColumns(): void
    {
        $document = $this->get('/cp-fleets/1?include=ships.crew.staff,flagship.crew.staff');

        self::assertSame(self::REDS_STAFF, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'staff')));
        self::assertSame(self::BLUES_STAFF, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'staff')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNestedStoredPivotIsReadOncePerLevelNotOncePerCrew(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $this->get('/cp-ships?include=crew.staff');

        $pivotReads = \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_contains($sql, 'cp_shift'),
        ));
        self::assertNotEmpty($pivotReads);
        foreach ($pivotReads as $sql) {
            self::assertDoesNotMatchRegularExpression('/crew_id\s*=\s*\?/', $sql, \implode("\n", $logger->statements()));
        }
        self::assertLessThanOrEqual(2, \count($pivotReads), \implode("\n", $pivotReads));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aStoredPivotLinkageRenderedWithoutAnIncludeRendersItsColumns(): void
    {
        $document = $this->get('/cp-crews/1?include=members');
        self::assertSame(self::REDS_STAFF, $this->pivots($this->linkage($document, 'staff')));

        $document = $this->get('/cp-ships?include=crew');
        self::assertSame(self::REDS_STAFF, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'staff')));
        self::assertSame(self::BLUES_STAFF, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'staff')));

        $document = $this->get('/cp-crews');
        self::assertSame(self::REDS_STAFF, $this->pivots($this->dataLinkage($document, 'cp-crews', '1', 'staff')));
        self::assertSame(self::BLUES_STAFF, $this->pivots($this->dataLinkage($document, 'cp-crews', '2', 'staff')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aStoredPivotLinkageRenderedWithoutAnIncludeIsReadOncePerLevel(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        foreach (['/cp-crews', '/cp-ships?include=crew'] as $path) {
            $logger->reset();
            $this->get($path);

            $pivotReads = \array_values(\array_filter(
                $logger->statements(),
                static fn(string $sql): bool => \str_contains($sql, 'cp_shift'),
            ));
            self::assertNotEmpty($pivotReads, $path);
            foreach ($pivotReads as $sql) {
                self::assertDoesNotMatchRegularExpression('/crew_id\s*=\s*\?/', $sql, $path . "\n" . \implode("\n", $logger->statements()));
            }
            self::assertLessThanOrEqual(2, \count($pivotReads), $path . "\n" . \implode("\n", $pivotReads));
        }
    }
}
