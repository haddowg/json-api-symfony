<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * `?withCount` against collections paged by cursor, asserted identically on the
 * in-memory ({@see InMemoryCursorWithCountTest}) and Doctrine-sqlite
 * ({@see DoctrineCursorWithCountTest}) kernels over the shared `cursorWidgets` and
 * `cursorShelves` seed.
 *
 * A `_self_` count on a cursor page is the size of the filtered and scoped collection,
 * whatever page the cursor points at, and it renders only as the top-level
 * `meta.total`: a cursor page carries no `meta.page.total` and no `last` link.
 */
abstract class CursorWithCountConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string COUNTABLE_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const string WINDOWED_COUNTABLE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"';

    // --- the primary collection -----------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:profiles')]
    public function withCountSelfRendersTheFilteredTotalOnACursorPage(): void
    {
        // The news widgets are 3, 5, 6 and 8. Sorted newest first, NULL counting as the
        // largest value: 6, 8, 3, 5.
        $document = $this->countedDocument('/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news&sort=-releasedAt,-id');

        self::assertSame(['6', '8'], $this->ids($document));
        self::assertSame([
            'total' => 4,
            'page' => ['perPage' => 2, 'from' => '6', 'to' => '8', 'hasMore' => true],
        ], $document['meta'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));
        self::assertContains(CountableProfile::URI, $this->appliedProfiles($document));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theTotalIgnoresTheCursorBoundsOnEveryPage(): void
    {
        $first = $this->countedDocument('/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news&sort=-releasedAt,-id');
        $next = $this->links($first)['next'] ?? null;
        self::assertNotNull($next);

        $second = $this->countedDocument($this->relativePath($this->href($next)));
        self::assertSame(['3', '5'], $this->ids($second));
        self::assertSame(4, $this->meta($second)['total'] ?? null);
        self::assertSame(['perPage' => 2, 'from' => '3', 'to' => '5', 'hasMore' => false], $this->meta($second)['page'] ?? null);

        $prev = $this->links($second)['prev'] ?? null;
        self::assertNotNull($prev);
        $back = $this->countedDocument($this->relativePath($this->href($prev)));
        self::assertSame(['6', '8'], $this->ids($back));
        self::assertSame(4, $this->meta($back)['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfCountsTheWholeCollectionWithoutAFilter(): void
    {
        $document = $this->countedDocument('/cursorWidgets?withCount=_self_&page[size]=3');

        self::assertSame(8, $this->meta($document)['total'] ?? null);
        self::assertArrayNotHasKey('total', $this->pageMeta($document));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCursorPageStaysCountFreeWhenNotAsked(): void
    {
        $document = $this->countedDocument('/cursorWidgets?page[size]=2&filter[category]=news');

        self::assertArrayNotHasKey('total', $this->meta($document));
    }

    // --- the related collection -----------------------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnARelatedCursorPageCountsInsideTheParentScope(): void
    {
        // Shelf 1 holds every widget, four of them guides; shelf 2 holds only news.
        $document = $this->countedDocument('/cursorShelves/1/widgets?withCount=_self_&page[size]=2&filter[category]=guide');
        self::assertSame(['1', '2'], $this->ids($document));
        self::assertSame([
            'total' => 4,
            'page' => ['perPage' => 2, 'from' => '1', 'to' => '2', 'hasMore' => true],
        ], $document['meta'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));

        $none = $this->countedDocument('/cursorShelves/2/widgets?withCount=_self_&page[size]=2&filter[category]=guide');
        self::assertSame([], $this->ids($none));
        self::assertSame(0, $this->meta($none)['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnAPivotRelatedCursorPageCountsInsideTheParentScope(): void
    {
        $document = $this->countedDocument('/cursorShelves/1/pivotWidgets?withCount=_self_&page[size]=2&filter[category]=news');

        self::assertCount(2, $this->ids($document));
        self::assertSame(4, $this->meta($document)['total'] ?? null);
        self::assertArrayNotHasKey('total', $this->pageMeta($document));
        self::assertArrayNotHasKey('last', $this->links($document));
    }

    // --- the relationship (linkage) endpoint ----------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnACursorLinkageRendersTheTotal(): void
    {
        $document = $this->countedDocument('/cursorShelves/1/relationships/widgets?withCount=_self_&page[size]=2&filter[category]=news');

        self::assertSame(['3', '5'], $this->ids($document));
        self::assertSame(['total' => 4], $document['meta'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnAPivotCursorLinkageRendersTheTotal(): void
    {
        $document = $this->countedDocument('/cursorShelves/2/relationships/pivotWidgets?withCount=_self_&page[size]=3');

        self::assertCount(3, $this->ids($document));
        self::assertSame(4, $this->meta($document)['total'] ?? null);
        self::assertArrayNotHasKey('last', $this->links($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aCursorLinkageStaysCountFreeWhenNotAsked(): void
    {
        $document = $this->countedDocument('/cursorShelves/1/relationships/widgets?page[size]=2');

        self::assertArrayNotHasKey('meta', $document);
    }

    // --- a relation's count in its relationship object ------------------------

    #[Test]
    #[Group('spec:profiles')]
    public function aCursorPagedRelationCountsIntoItsRelationshipMeta(): void
    {
        $document = $this->countedDocument('/cursorShelves/2?withCount=widgets,pivotWidgets');

        $relationships = $this->relationshipsOf($document['data'] ?? null);
        self::assertSame(4, $this->meta($relationships['widgets'])['total'] ?? null);
        self::assertSame(4, $this->meta($relationships['pivotWidgets'])['total'] ?? null);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aCursorWindowedIncludeCountsEachParentsWholeRelation(): void
    {
        $response = $this->handle(
            '/cursorShelves?include=widgets&withCount=widgets&relatedQuery[widgets][sort]=priority',
            extraServer: ['HTTP_ACCEPT' => self::WINDOWED_COUNTABLE_ACCEPT],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $totals = [];
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $shelf) {
            self::assertIsArray($shelf);
            $widgets = $this->relationshipsOf($shelf)['widgets'];
            self::assertIsString($shelf['id'] ?? null);
            $totals[$shelf['id']] = $this->meta($widgets)['total'] ?? null;
            self::assertCount(2, \is_array($widgets['data'] ?? null) ? $widgets['data'] : [], 'the window is the cursor page of two');
            self::assertArrayNotHasKey('last', \is_array($widgets['links'] ?? null) ? $widgets['links'] : []);
        }

        self::assertSame(['1' => 8, '2' => 4, '3' => 2], $totals);
    }

    // --- helpers ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function countedDocument(string $path): array
    {
        $response = $this->handle($path, extraServer: ['HTTP_ACCEPT' => self::COUNTABLE_ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id'] ?? null);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function meta(array $document): array
    {
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function pageMeta(array $document): array
    {
        $page = $this->meta($document)['page'] ?? [];
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function links(array $document): array
    {
        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        return $links;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    private function appliedProfiles(array $document): array
    {
        $jsonapi = $document['jsonapi'] ?? [];
        self::assertIsArray($jsonapi);
        $profiles = $jsonapi['profile'] ?? [];
        self::assertIsArray($profiles);

        return \array_values($profiles);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function relationshipsOf(mixed $resource): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, array<string, mixed>> $relationships */
        return $relationships;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link)) {
            $link = $link['href'] ?? null;
        }
        self::assertIsString($link);

        return $link;
    }

    private function relativePath(string $url): string
    {
        $parts = \parse_url($url);
        self::assertIsArray($parts);

        return ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
