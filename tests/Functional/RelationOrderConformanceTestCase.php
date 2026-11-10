<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Server\ServableResourceWarmer;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\CollectingLogger;
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
    #[Group('spec:fetching-relationships')]
    public function anIdentifiedRelationsLinkageCarriesItsPivotInRankOrder(): void
    {
        $document = $this->document('/shelves/1');

        self::assertSame(['magazines:1', 'books:2', 'books:1', 'magazines:2'], $this->linkageMembers($document['data'] ?? null, 'items'));
        self::assertSame(
            [['rank' => 1, 'note' => 'a'], ['rank' => 2, 'note' => 'b'], ['rank' => 3, 'note' => 'c'], ['rank' => 4, 'note' => 'd']],
            $this->linkagePivots($document['data'] ?? null, 'items'),
        );

        $included = $this->document('/shelves/1?include=items');
        self::assertSame(['magazines:1', 'books:2', 'books:1', 'magazines:2'], $this->linkageMembers($included['data'] ?? null, 'items'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function anIdentifiedRelationsEndpointsSortAndFilterItsLinkRows(): void
    {
        self::assertSame(['magazines:1', 'books:2', 'books:1', 'magazines:2'], $this->members('/shelves/1/relationships/items'));
        self::assertSame(['magazines:2', 'books:1', 'books:2', 'magazines:1'], $this->members('/shelves/1/relationships/items?sort=-rank'));
        self::assertSame(['magazines:2', 'books:1'], $this->members('/shelves/1/relationships/items?sort=-note&filter[minRank]=3'));
        self::assertSame(['books:1', 'magazines:2'], $this->members('/shelves/1/relationships/items?page[number]=2&page[size]=2'));

        self::assertSame(['magazines:1', 'books:2', 'books:1', 'magazines:2'], $this->members('/shelves/1/items'));
        self::assertSame(['books:1', 'magazines:2'], $this->members('/shelves/1/items?filter[minRank]=3'));
        self::assertSame(['magazines:2', 'books:1', 'books:2', 'magazines:1'], $this->members('/shelves/1/items?sort=-rank'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointRendersEachMembersPivot(): void
    {
        $data = $this->document('/shelves/1/items')['data'] ?? null;
        self::assertIsArray($data);

        $pivots = \array_map(static fn(mixed $item): mixed => \is_array($item) && \is_array($item['meta'] ?? null) ? ($item['meta']['pivot'] ?? null) : null, $data);
        self::assertSame([['rank' => 1, 'note' => 'a'], ['rank' => 2, 'note' => 'b'], ['rank' => 3, 'note' => 'c'], ['rank' => 4, 'note' => 'd']], $pivots);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aKeyOutsideTheLinkRowsVocabularyIsA400(): void
    {
        foreach (['/shelves/1/relationships/items?sort=title', '/shelves/1/items?filter[title]=x', '/shelves/1/items?sort=nope'] as $path) {
            $response = $this->handle(self::BASE_URI . $path);
            self::assertSame(400, $response->getStatusCode(), $path . ': ' . $response->getContent());
        }
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aReplaceWritesEachMembersPivot(): void
    {
        $response = $this->handle(self::BASE_URI . '/shelves/1/relationships/items', 'PATCH', ['data' => [
            ['type' => 'magazines', 'id' => '2', 'meta' => ['pivot' => ['rank' => 1]]],
            ['type' => 'books', 'id' => '1', 'meta' => ['pivot' => ['rank' => 2, 'note' => 'x']]],
            ['type' => 'books', 'id' => '3', 'meta' => ['pivot' => ['rank' => 3, 'note' => 'new']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->document('/shelves/1');
        self::assertSame(['magazines:2', 'books:1', 'books:3'], $this->linkageMembers($document['data'] ?? null, 'items'));
        self::assertSame(
            [['rank' => 1, 'note' => 'd'], ['rank' => 2, 'note' => 'x'], ['rank' => 3, 'note' => 'new']],
            $this->linkagePivots($document['data'] ?? null, 'items'),
        );
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function anAddWritesTheNewMembersPivot(): void
    {
        $response = $this->handle(self::BASE_URI . '/shelves/1/relationships/items', 'POST', ['data' => [
            ['type' => 'books', 'id' => '4', 'meta' => ['pivot' => ['rank' => 0, 'note' => 'first']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['books:4', 'magazines:1', 'books:2', 'books:1', 'magazines:2'], $this->members('/shelves/1/relationships/items'));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    #[Group('spec:errors')]
    public function aNewMemberWithoutARequiredPivotFieldIsA422(): void
    {
        $response = $this->handle(self::BASE_URI . '/shelves/1/relationships/items', 'POST', ['data' => [
            ['type' => 'books', 'id' => '4', 'meta' => ['pivot' => ['note' => 'no rank']]],
        ]]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());

        // An existing member keeps its stored rank, so a partial update is fine.
        $existing = $this->handle(self::BASE_URI . '/shelves/1/relationships/items', 'POST', ['data' => [
            ['type' => 'books', 'id' => '1', 'meta' => ['pivot' => ['note' => 'kept rank']]],
        ]]);
        self::assertSame(200, $existing->getStatusCode(), (string) $existing->getContent());
        $document = $this->document('/shelves/1');
        self::assertSame(['rank' => 3, 'note' => 'kept rank'], $this->linkagePivots($document['data'] ?? null, 'items')[2] ?? null);
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aWholeResourceWriteWritesItsLinkagesPivot(): void
    {
        $response = $this->handle(self::BASE_URI . '/shelves/2', 'PATCH', ['data' => [
            'type' => 'shelves',
            'id' => '2',
            'relationships' => ['items' => ['data' => [
                ['type' => 'books', 'id' => '5', 'meta' => ['pivot' => ['rank' => 2, 'note' => 'later']]],
                ['type' => 'magazines', 'id' => '1', 'meta' => ['pivot' => ['rank' => 1, 'note' => 'sooner']]],
            ]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->document('/shelves/2');
        self::assertSame(['magazines:1', 'books:5'], $this->linkageMembers($document['data'] ?? null, 'items'));
        self::assertSame([['rank' => 1, 'note' => 'sooner'], ['rank' => 2, 'note' => 'later']], $this->linkagePivots($document['data'] ?? null, 'items'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aClosureRelationIsQueriedInMemoryAlike(): void
    {
        self::assertSame(['magazines:1', 'books:2', 'books:1', 'magazines:2'], $this->linkageMembers($this->document('/shelves/1')['data'] ?? null, 'closureItems'));
        self::assertSame(['magazines:2', 'books:1'], $this->members('/shelves/1/relationships/closureItems?sort=-rank&filter[minRank]=3'));
        self::assertSame(['books:1', 'magazines:2'], $this->members('/shelves/1/closureItems?filter[minRank]=3'));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aClosureRelationsHookWritesItsRowsAndTheBundleItsPivot(): void
    {
        $response = $this->handle(self::BASE_URI . '/shelves/1/relationships/closureItems', 'PATCH', ['data' => [
            ['type' => 'books', 'id' => '3', 'meta' => ['pivot' => ['rank' => 1, 'note' => 'new']]],
            ['type' => 'books', 'id' => '1', 'meta' => ['pivot' => ['rank' => 2]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->document('/shelves/1');
        self::assertSame(['books:3', 'books:1'], $this->linkageMembers($document['data'] ?? null, 'closureItems'));
        self::assertSame([['rank' => 1, 'note' => 'new'], ['rank' => 2, 'note' => 'c']], $this->linkagePivots($document['data'] ?? null, 'closureItems'));
    }

    #[Test]
    public function warmUpReportsTheRelationQueriedInMemory(): void
    {
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(CollectingLogger::class, $logger);
        $warmer = static::getContainer()->get(ServableResourceWarmer::class);
        self::assertInstanceOf(ServableResourceWarmer::class, $warmer);
        $logger->reset();

        $warmer->warmUp(\sys_get_temp_dir());

        $notices = $logger->messages('notice');
        self::assertCount(1, $notices);
        self::assertStringContainsString('MorphToMany relation "closureItems" reads its link rows through an identifiedBy() closure', $notices[0] ?? '');
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

        foreach (['/shelves/{id}/items', '/shelves/{id}/relationships/items'] as $path) {
            self::assertSame(['rank'], $this->sortSchema($document, $path)['default'] ?? null, $path);
            $paths = $document['paths'] ?? null;
            self::assertIsArray($paths);
            self::assertStringContainsString('"filter[minRank]"', (string) \json_encode($paths[$path] ?? null), $path);
        }
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
     * The `type:id` of each member a document's primary data lists, in order.
     *
     * @return list<string>
     */
    protected function members(string $path): array
    {
        $data = $this->document($path)['data'] ?? null;
        self::assertIsArray($data);

        return $this->typeIds($data);
    }

    /**
     * The `type:id` of each linkage member of a resource object's relationship.
     *
     * @return list<string>
     */
    protected function linkageMembers(mixed $resource, string $relationship): array
    {
        return $this->typeIds($this->linkageData($resource, $relationship));
    }

    /**
     * The `meta.pivot` of each linkage member of a resource object's relationship.
     *
     * @return list<mixed>
     */
    protected function linkagePivots(mixed $resource, string $relationship): array
    {
        return \array_values(\array_map(
            static fn(mixed $item): mixed => \is_array($item) && \is_array($item['meta'] ?? null) ? ($item['meta']['pivot'] ?? null) : null,
            $this->linkageData($resource, $relationship),
        ));
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<string>
     */
    private function typeIds(array $items): array
    {
        return \array_map(
            static fn(mixed $item): string => \is_array($item) && \is_string($item['type'] ?? null) && \is_string($item['id'] ?? null) ? $item['type'] . ':' . $item['id'] : '',
            \array_values($items),
        );
    }

    /**
     * @return array<mixed>
     */
    private function linkageData(mixed $resource, string $relationship): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relationship] ?? null;
        self::assertIsArray($object);
        $data = $object['data'] ?? null;
        self::assertIsArray($data, \sprintf('The "%s" relationship renders no linkage data.', $relationship));

        return $data;
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
