<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderBook;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderPick;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderShelf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relation-order conformance over Doctrine, plus the pivot relation the in-memory
 * provider can't serve: shelf 1's `picks` by rank are books 3, 4, 1, inserted as 1, 3, 4.
 */
final class DoctrineRelationOrderTest extends RelationOrderConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OrderDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // One flush per row keeps each generated id the fixture's.
        $shelves = [];
        foreach (OrderFixtures::SHELVES as $id => $name) {
            $entityManager->persist($shelves[$id] = new OrderShelf(name: $name));
            $entityManager->flush();
        }
        $books = [];
        foreach (OrderFixtures::BOOKS as $id => [$title, $year, $shelf]) {
            $entityManager->persist($books[$id] = new OrderBook(title: $title, year: $year, shelf: $shelves[$shelf]));
            $entityManager->flush();
        }
        foreach (OrderFixtures::PICKS as [$shelf, $book, $rank]) {
            $entityManager->persist(new OrderPick(shelf: $shelves[$shelf], book: $books[$book] ?? throw new \LogicException('No book ' . $book), rank: $rank));
            $entityManager->flush();
        }
        $entityManager->clear();
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotFieldOrdersThePivotRelationOnEveryPath(): void
    {
        self::assertSame(['3', '4', '1'], $this->linkage($this->document('/shelves/1')['data'] ?? null, 'picks'));
        self::assertSame(['3', '4', '1'], $this->linkage($this->document('/shelves/1?include=picks')['data'] ?? null, 'picks'));
        self::assertSame(['3', '4', '1'], $this->ids('/shelves/1/picks'));
        self::assertSame(['3', '4', '1'], $this->ids('/shelves/1/relationships/picks'));
        self::assertSame(['1', '4', '3'], $this->ids('/shelves/1/picks?sort=-rank'));
        self::assertSame(['3', '1', '4'], $this->ids('/shelves/1/relationships/picks?sort=title'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aCollectionsOrderedLinkageLoadsOncePerRelation(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $this->document('/shelves');

        // One ordered read each for books and booksByYear across the three shelves.
        $bookReads = \array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_starts_with(\strtolower(\ltrim($sql)), 'select')
                && \preg_match('/\bfrom ro_book\b/i', $sql) === 1
                && !\str_contains(\strtolower($sql), 'ro_pick'),
        );
        self::assertCount(2, $bookReads, \implode("\n", $logger->statements()));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
