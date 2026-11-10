<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderBook;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderMagazine;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderPick;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderShelf;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderShelfItem;
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
        foreach (OrderFixtures::BOOKS as $id => [$title, $year, $shelf, $position]) {
            $entityManager->persist($books[$id] = new OrderBook(title: $title, year: $year, shelf: $shelves[$shelf], position: $position));
            $entityManager->flush();
        }
        foreach (OrderFixtures::PICKS as $position => [$shelf, $book, $rank]) {
            $entityManager->persist(new OrderPick(shelf: $shelves[$shelf], book: $books[$book] ?? throw new \LogicException('No book ' . $book), rank: $rank, position: $position));
            $entityManager->flush();
        }
        foreach (OrderFixtures::MAGAZINES as $title) {
            $entityManager->persist(new OrderMagazine(title: $title));
            $entityManager->flush();
        }
        foreach (OrderFixtures::ITEMS as $position => [$shelf, $itemType, $itemId, $rank, $note]) {
            $entityManager->persist(new OrderShelfItem(shelf: $shelves[$shelf], itemType: $itemType, itemId: $itemId, rank: $rank, note: $note, position: $position));
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

        // One ordered read each for books, booksByYear and shelvedBooks across the three
        // shelves.
        $bookReads = \array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_starts_with(\strtolower(\ltrim($sql)), 'select')
                && \preg_match('/\bfrom ro_book\b/i', $sql) === 1
                && !\str_contains(\strtolower($sql), 'ro_pick'),
        );
        self::assertCount(3, $bookReads, \implode("\n", $logger->statements()));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aLinkRowPageIsOneQueryAndLoadsNoOtherRow(): void
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        self::assertSame(['books:1', 'magazines:2'], $this->members('/shelves/1/relationships/items?page[number]=2&page[size]=2'));

        $rowReads = \array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_starts_with(\strtolower(\ltrim($sql)), 'select')
                && \preg_match('/\bfrom ro_shelf_item\b/i', $sql) === 1,
        );
        self::assertCount(1, $rowReads, \implode("\n", $logger->statements()));
        self::assertStringContainsString('LIMIT', \implode('', $rowReads));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aPivotRelationWritesItsPositionsUnderAUniqueKey(): void
    {
        $this->replace('picks', [['books', '4'], ['books', '1'], ['books', '3']]);
        // Read by rank, books 3, 4 and 1, each carrying its new position.
        self::assertSame([2, 0, 1], $this->pivotPositions());

        $this->replace('picks', [['books', '3'], ['books', '4'], ['books', '1']]);
        self::assertSame([0, 1, 2], $this->pivotPositions());

        $response = $this->handle(self::BASE_URI . '/shelves/1/relationships/picks', 'POST', ['data' => [['type' => 'books', 'id' => '2', 'meta' => ['pivot' => ['rank' => 4]]]]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([0, 1, 2, 3], $this->pivotPositions());
    }

    /**
     * @return list<mixed>
     */
    private function pivotPositions(): array
    {
        return \array_map(
            static fn(mixed $pivot): mixed => \is_array($pivot) ? ($pivot['position'] ?? null) : null,
            $this->linkagePivots($this->document('/shelves/1')['data'] ?? null, 'picks'),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
