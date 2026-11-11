<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder\MappedOrderDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder\MoBook;
use haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder\MoShelf;
use haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder\MoTag;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many with no `defaultSort()` renders its linkage in the order its association's
 * `#[ORM\OrderBy]` gives a lazy load, whether the batch runs for an include or for
 * linkage alone. Books and tags are inserted out of that order.
 */
#[Group('spec:fetching-relationships')]
final class DoctrineMappedOrderTest extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return MappedOrderDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $tags = [];
        foreach (['beta', 'gamma', 'alpha', 'delta'] as $label) {
            $entityManager->persist($tags[$label] = new MoTag(label: $label));
        }

        foreach (['Fiction' => ['Mort', 'Dune', 'Wolf Hall'], 'History' => ['SPQR', 'Postwar', 'The Silk Roads']] as $name => $titles) {
            $shelf = new MoShelf(name: $name);
            $entityManager->persist($shelf);
            foreach ($titles as $title) {
                $entityManager->persist(new MoBook(title: $title, shelf: $shelf));
            }
            foreach ($name === 'Fiction' ? ['beta', 'alpha', 'gamma'] : ['delta', 'alpha', 'beta'] as $label) {
                $shelf->tags->add($tags[$label]);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aToManyRendersItsLinkageInTheMappedOrder(): void
    {
        $this->assertMappedOrder('/moShelves');
    }

    #[Test]
    public function anIncludedToManyRendersItsLinkageInTheMappedOrder(): void
    {
        $this->assertMappedOrder('/moShelves?include=books,tags');
    }

    #[Test]
    public function aSingleShelfRendersItsLinkageInTheMappedOrder(): void
    {
        $baseline = $this->lazyLoadedIds('1');

        $response = $this->handle(self::BASE_URI . '/moShelves/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $shelf = $this->decode($response)['data'] ?? null;
        self::assertIsArray($shelf);
        self::assertSame($baseline['books'], $this->linkageIds($shelf, 'books'));
        self::assertSame($baseline['tags'], $this->linkageIds($shelf, 'tags'));
    }

    #[Test]
    public function aDefaultSortWinsOverTheMappedOrder(): void
    {
        $response = $this->handle(self::BASE_URI . '/moShelves/1?include=booksByTitle');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $shelf = $document['data'] ?? null;
        self::assertIsArray($shelf);
        $titles = [];
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $attributes = $resource['attributes'] ?? null;
            self::assertIsArray($attributes);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $titles[$id] = $attributes['title'] ?? null;
        }

        self::assertSame(
            ['Dune', 'Mort', 'Wolf Hall'],
            \array_map(static fn(string $id): mixed => $titles[$id] ?? null, $this->linkageIds($shelf, 'booksByTitle')),
        );
    }

    private function assertMappedOrder(string $uri): void
    {
        $response = $this->handle(self::BASE_URI . $uri);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(2, $data);
        foreach ($data as $shelf) {
            self::assertIsArray($shelf);
            $id = $shelf['id'] ?? null;
            self::assertIsString($id);
            $baseline = $this->lazyLoadedIds($id);
            self::assertSame($baseline['books'], $this->linkageIds($shelf, 'books'), 'books of shelf ' . $id);
            self::assertSame($baseline['tags'], $this->linkageIds($shelf, 'tags'), 'tags of shelf ' . $id);
        }
    }

    /**
     * The shelf's book and tag ids, read off a lazy load of its collections.
     *
     * @return array{books: list<string>, tags: list<string>}
     */
    private function lazyLoadedIds(string $id): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $shelf = $entityManager->find(MoShelf::class, (int) $id);
        self::assertInstanceOf(MoShelf::class, $shelf);
        $ids = [
            'books' => \array_values(\array_map(static fn(MoBook $book): string => (string) $book->id, $shelf->books->toArray())),
            'tags' => \array_values(\array_map(static fn(MoTag $tag): string => (string) $tag->id, $shelf->tags->toArray())),
        ];
        $entityManager->clear();

        if ($id === '1') {
            self::assertSame(['3', '1', '2'], $ids['books'], 'the mapped order is not the insertion order');
        }

        return $ids;
    }

    /**
     * @param array<mixed> $resource
     *
     * @return list<string>
     */
    private function linkageIds(array $resource, string $relation): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$relation] ?? null;
        self::assertIsArray($relationship);
        $data = $relationship['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
