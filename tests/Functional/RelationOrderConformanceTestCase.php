<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many's default read order on every read path, over the books of
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderFixtures}.
 *
 * Shelf 1's books by title descending, the order `books` declares, are 2, 4, 1, 3; by
 * year, the books type's own order that `booksByYear` keeps, they are 4, 2, 1, 3. Their
 * ids alone would read 1, 2, 3, 4, so a path that ignored the order would show it.
 */
abstract class RelationOrderConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const string BASE_URI = 'https://example.test';

    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theLinkageOfAResourceListsItsMembersInTheRelationsOrder(): void
    {
        $document = $this->document('/shelves/1');

        self::assertSame(['2', '4', '1', '3'], $this->linkage($document['data'] ?? null, 'books'));
        self::assertSame(['4', '2', '1', '3'], $this->linkage($document['data'] ?? null, 'booksByYear'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function eachParentOfACollectionListsItsMembersInOrder(): void
    {
        $document = $this->document('/shelves');
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        self::assertSame(['2', '4', '1', '3'], $this->linkage($data[0] ?? null, 'books'));
        self::assertSame(['6', '5'], $this->linkage($data[1] ?? null, 'books'));
        self::assertSame(['5', '6'], $this->linkage($data[1] ?? null, 'booksByYear'));
        self::assertSame([], $this->linkage($data[2] ?? null, 'books'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludedRelationKeepsItsOrder(): void
    {
        $document = $this->document('/shelves/1?include=books,booksByYear');

        self::assertSame(['2', '4', '1', '3'], $this->linkage($document['data'] ?? null, 'books'));
        self::assertSame(['4', '2', '1', '3'], $this->linkage($document['data'] ?? null, 'booksByYear'));
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertCount(4, $included);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointServesTheRelationsOrder(): void
    {
        self::assertSame(['2', '4', '1', '3'], $this->ids('/shelves/1/books'));
        self::assertSame(['4', '2', '1', '3'], $this->ids('/shelves/1/booksByYear'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointServesTheRelationsOrder(): void
    {
        self::assertSame(['2', '4', '1', '3'], $this->ids('/shelves/1/relationships/books'));
        self::assertSame(['4', '2', '1', '3'], $this->ids('/shelves/1/relationships/booksByYear'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aRequestedSortReplacesTheWholeDefault(): void
    {
        self::assertSame(['3', '1', '4', '2'], $this->ids('/shelves/1/books?sort=title'));
        self::assertSame(['3', '1', '2', '4'], $this->ids('/shelves/1/relationships/books?sort=-year'));
        self::assertSame(['3', '1', '4', '2'], $this->ids('/shelves/1/booksByYear?sort=title'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aRelationshipQueriesWindowServesTheRelationsOrder(): void
    {
        $document = $this->document('/shelves?include=books', self::PROFILE_ACCEPT);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['2', '4', '1', '3'], $this->linkage($data[0] ?? null, 'books'));
        self::assertSame(['6', '5'], $this->linkage($data[1] ?? null, 'books'));

        $sorted = $this->document('/shelves/1?include=books&relatedQuery[books][sort]=year', self::PROFILE_ACCEPT);
        self::assertSame(['4', '2', '1', '3'], $this->linkage($sorted['data'] ?? null, 'books'));
    }

    #[Test]
    #[Group('openapi')]
    public function theDocumentStatesOnlyTheRelationsOwnOrder(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        foreach (['/shelves/{id}/books', '/shelves/{id}/relationships/books'] as $path) {
            self::assertSame(['-title'], $this->sortSchema($document, $path)['default'] ?? null, $path);
        }
        self::assertArrayNotHasKey('default', $this->sortSchema($document, '/shelves/{id}/booksByYear'));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function sortSchema(array $document, string $path): array
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item, $path);
        $operation = $item['get'] ?? null;
        self::assertIsArray($operation, $path);
        $parameters = $operation['parameters'] ?? [];
        self::assertIsArray($parameters);
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'sort' && \is_array($parameter['schema'] ?? null)) {
                $schema = [];
                foreach ($parameter['schema'] as $key => $value) {
                    $schema[(string) $key] = $value;
                }

                return $schema;
            }
        }

        self::fail(\sprintf('%s takes no sort parameter.', $path));
    }

    /**
     * @return array<string, mixed>
     */
    protected function document(string $path, string $accept = 'application/vnd.api+json'): array
    {
        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The ids of a document's primary data, in order.
     *
     * @return list<string>
     */
    protected function ids(string $path): array
    {
        $data = $this->document($path)['data'] ?? null;
        self::assertIsArray($data);

        return \array_map(static fn(mixed $item): string => \is_array($item) && \is_string($item['id'] ?? null) ? $item['id'] : '', \array_values($data));
    }

    /**
     * The linkage ids of a resource object's named relationship, in order.
     *
     * @return list<string>
     */
    protected function linkage(mixed $resource, string $relationship): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relationship] ?? null;
        self::assertIsArray($object);
        $data = $object['data'] ?? null;
        self::assertIsArray($data, \sprintf('The "%s" relationship renders no linkage data.', $relationship));

        return \array_map(static fn(mixed $item): string => \is_array($item) && \is_string($item['id'] ?? null) ? $item['id'] : '', \array_values($data));
    }
}
