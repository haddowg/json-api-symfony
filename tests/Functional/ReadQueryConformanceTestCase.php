<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-1 acceptance suite: filtering, sorting, pagination and sparse
 * fieldsets on `GET /articles`, asserted as spec-compliant JSON:API documents.
 *
 * Abstract over the kernel so the **same assertions** run against the
 * in-memory provider ({@see InMemoryReadQueryTest}) and the Doctrine provider
 * ({@see DoctrineReadQueryTest}); both kernels serve the shared
 * `BaseArticleResource` declaration over the shared {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures}
 * seeds, so a test failing on one provider and not the other localizes the bug
 * to that provider's execution.
 *
 * Since G21 the `articles` paginator is **count-free by default** but the resource
 * is `countable()`, so the count-dependent pagination assertions (`meta.page.total`,
 * `lastPage`, the `last` link) opt into the total with `?withCount=_self_` under the
 * negotiated Countable profile ({@see fetchCountedDocument()}); the count-free
 * default is asserted directly with the bare {@see fetchDocument()}.
 */
abstract class ReadQueryConformanceTestCase extends JsonApiFunctionalTestCase
{
    // `?withCount=_self_` is gated behind the Countable profile, so a primary-collection
    // count fetch negotiates it.
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    // --- filtering -----------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filteringByAnExactMatchNarrowsTheCollection(): void
    {
        $document = $this->fetchDocument('/articles?filter[title]=Zebra%20patterns');

        self::assertSame(['4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filteringByAContainsMatchNarrowsTheCollection(): void
    {
        $document = $this->fetchDocument('/articles?filter[titleContains]=article');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aContainsFilterMatchesCaseInsensitively(): void
    {
        // The `like` contract is ASCII-case-insensitive contains on BOTH
        // providers (core's stripos witness vs LOWER()'d SQL LIKE) — a probe
        // that only matches under case-folding pins the parity.
        $document = $this->fetchDocument('/articles?filter[titleContains]=ARTICLE');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filteringByAnIdSetNarrowsTheCollection(): void
    {
        $document = $this->fetchDocument('/articles?filter[id]=1,4&sort=title');

        self::assertSame(['1', '4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function filtersCombineConjunctively(): void
    {
        $document = $this->fetchDocument('/articles?filter[id]=1,2,4&filter[titleContains]=article');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anUnknownFilterKeyRendersA400ErrorDocument(): void
    {
        $response = $this->handle('/articles?filter[nope]=x');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTERING_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[nope]'], $error['source'] ?? null);
    }

    // --- singular filters ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingularFilterCollapsesTheCollectionToASingleResource(): void
    {
        // `exactTitle` is declared singular(): applying it renders the matched
        // resource as the document's primary `data` *object*, not a one-element
        // array — the JSON:API zero-to-one shape (core ADR 0039).
        $document = $this->fetchDocument('/articles?filter[exactTitle]=Zebra%20patterns');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('articles', $data['type'] ?? null);
        self::assertSame('4', $data['id'] ?? null);

        // Zero-to-one is not a collection, so it carries no pagination meta even
        // though the resource declares a default paginator.
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);
        self::assertArrayNotHasKey('page', $meta);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aSingularFilterWithNoMatchRendersDataNull(): void
    {
        // Zero matches is `data: null` with a 200 — not a 404 (the endpoint is the
        // collection, which exists; the singular result is simply empty).
        $document = $this->fetchDocument('/articles?filter[exactTitle]=No%20Such%20Title');

        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function withoutTheSingularFilterTheSameEndpointStaysACollection(): void
    {
        // The collapse is triggered only by the applied singular filter; the bare
        // collection still renders `data` as an array.
        $document = $this->fetchDocument('/articles');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertArrayHasKey(0, $data);
    }

    // --- sorting -------------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function sortingAscendingOrdersTheCollection(): void
    {
        $document = $this->fetchDocument('/articles?sort=title');

        self::assertSame(['5', '3', '1', '2', '4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aMinusPrefixSortsDescending(): void
    {
        $document = $this->fetchDocument('/articles?sort=-title');

        self::assertSame(['4', '2', '1', '3', '5'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function multiFieldSortUsesTheFirstFieldAsThePrimaryKey(): void
    {
        // category carries ties (guide: 1,2,4 / news: 3,5), broken by title.
        // A wrong composition (last field primary) would yield pure title
        // order [5,3,1,2,4] instead.
        $ascending = $this->fetchDocument('/articles?sort=category,title');
        self::assertSame(['1', '2', '4', '5', '3'], $this->ids($ascending));

        // The secondary direction flips within each category group only.
        $descending = $this->fetchDocument('/articles?sort=category,-title');
        self::assertSame(['4', '2', '1', '3', '5'], $this->ids($descending));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function anUnknownSortFieldRendersA400ErrorDocument(): void
    {
        $response = $this->handle('/articles?sort=nope');

        self::assertSame(400, $response->getStatusCode());

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('SORTING_UNRECOGNIZED', $error['code'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aDeclaredButUnsortableFieldRendersA400ErrorDocument(): void
    {
        // `body` is a declared attribute but never opted into sorting, so it is
        // not part of the sort vocabulary.
        $response = $this->handle('/articles?sort=body');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($this->decode($response))['code'] ?? null);
    }

    // --- pagination ----------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function paginationWindowsTheSortedCollection(): void
    {
        // Counted under `?withCount=_self_` (G21 §6a): the total fans to BOTH the
        // top-level meta.total AND meta.page.total, from one count.
        $document = $this->fetchCountedDocument('/articles?sort=title&page[number]=2&page[size]=2');

        self::assertSame(['1', '2'], $this->ids($document));

        self::assertSame(5, $this->topLevelMeta($document)['total'] ?? null);

        $meta = $this->pageMeta($document);
        self::assertSame(2, $meta['currentPage'] ?? null);
        self::assertSame(2, $meta['perPage'] ?? null);
        self::assertSame(5, $meta['total'] ?? null);
        self::assertSame(3, $meta['lastPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountFreePageWindowsWithoutATotalOrLastLink(): void
    {
        // The G21 default: a bare `?page` (no `?withCount`) windows count-free — no
        // top-level meta.total, no meta.page.total/lastPage, no `last` link; `next`
        // is driven by the over-fetch `hasMore`, not a total.
        $document = $this->fetchDocument('/articles?sort=title&page[number]=2&page[size]=2');

        self::assertSame(['1', '2'], $this->ids($document));
        self::assertArrayNotHasKey('total', $this->topLevelMeta($document));

        $meta = $this->pageMeta($document);
        self::assertSame(2, $meta['currentPage'] ?? null);
        self::assertSame(2, $meta['perPage'] ?? null);
        self::assertArrayNotHasKey('total', $meta);
        self::assertArrayNotHasKey('lastPage', $meta);

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('last', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function paginationLinksNavigateAndPreserveTheQuery(): void
    {
        $document = $this->fetchCountedDocument('/articles?sort=title&page[number]=2&page[size]=2');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);

        foreach (['self', 'first', 'prev', 'next', 'last'] as $rel) {
            self::assertArrayHasKey($rel, $links, $rel);
        }

        self::assertSame(1, $this->pageNumberOf($links['first']));
        self::assertSame(1, $this->pageNumberOf($links['prev']));
        self::assertSame(3, $this->pageNumberOf($links['next']));
        self::assertSame(3, $this->pageNumberOf($links['last']));

        // Unrelated query params survive page navigation.
        self::assertStringContainsString('sort=title', $this->href($links['next']));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theLastPageIsPartialAndHasNoNextLink(): void
    {
        $document = $this->fetchCountedDocument('/articles?sort=title&page[number]=3&page[size]=2');

        self::assertSame(['4'], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayNotHasKey('next', $links);
        self::assertArrayHasKey('prev', $links);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function paginationDefaultsApplyWithoutPageParameters(): void
    {
        // The resource declares a PagePaginator (default size 15) and opts into the
        // count via `?withCount=_self_`, so a bare collection fetch is page 1 of one
        // page — with full page meta and the top-level meta.total.
        $document = $this->fetchCountedDocument('/articles');

        self::assertCount(5, $this->ids($document));

        self::assertSame(5, $this->topLevelMeta($document)['total'] ?? null);

        $meta = $this->pageMeta($document);
        self::assertSame(1, $meta['currentPage'] ?? null);
        self::assertSame(5, $meta['total'] ?? null);
        self::assertSame(1, $meta['lastPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anOutOfRangePageNumberIsServedAsTheFirstPageConsistently(): void
    {
        // window() and paginate() share one normalisation: page[number]=0 must
        // serve page 1 AND describe page 1 — data, meta, and links agree.
        $document = $this->fetchDocument('/articles?sort=title&page[number]=0&page[size]=2');

        self::assertSame(['5', '3'], $this->ids($document));
        self::assertSame(1, $this->pageMeta($document)['currentPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aZeroPageSizeRendersADegenerateEmptyPageNotAnError(): void
    {
        // page[size] is client-controlled: size 0 must not 500 (division by
        // zero in last-page math) — under `?withCount=_self_` it renders an empty
        // page with the total.
        $document = $this->fetchCountedDocument('/articles?page[size]=0');

        self::assertSame([], $this->ids($document));

        $meta = $this->pageMeta($document);
        self::assertSame(5, $meta['total'] ?? null);
        self::assertSame(0, $meta['lastPage'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function paginationComposesWithFiltering(): void
    {
        // 4 of the 5 articles survive the filter; page 2 of size 2 holds the
        // 3rd and 4th by title, and the total reflects the *filtered* count.
        $document = $this->fetchCountedDocument('/articles?filter[id]=1,2,3,5&sort=title&page[number]=2&page[size]=2');

        self::assertSame(['1', '2'], $this->ids($document));
        self::assertSame(4, $this->topLevelMeta($document)['total'] ?? null);
        self::assertSame(4, $this->pageMeta($document)['total'] ?? null);
    }

    // --- sparse fieldsets ------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function sparseFieldsetsNarrowTheAttributes(): void
    {
        $document = $this->fetchDocument('/articles/1?fields[articles]=title');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertArrayHasKey('title', $attributes);
        self::assertArrayNotHasKey('body', $attributes);
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * Fetches `$path` with `?withCount=_self_` appended and the Countable profile
     * negotiated, so the count-free-by-default `articles` collection (G21) renders
     * the total — `meta.total`, `meta.page.total`, `lastPage` and the `last` link.
     *
     * @return array<string, mixed>
     */
    private function fetchCountedDocument(string $path): array
    {
        $separator = \str_contains($path, '?') ? '&' : '?';
        $response = $this->handle($path . $separator . 'withCount=_self_', extraServer: [
            'HTTP_ACCEPT' => self::COUNTS_ACCEPT,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The document's top-level `meta` (asserted to be an array).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function topLevelMeta(array $document): array
    {
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * The ids of the document's primary data, in document order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('articles', $resource['type'] ?? null);

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function pageMeta(array $document): array
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);

        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * The `page[number]` a pagination link points at.
     */
    private function pageNumberOf(mixed $link): int
    {
        \parse_str((string) \parse_url($this->href($link), \PHP_URL_QUERY), $query);

        $page = $query['page'] ?? null;
        self::assertIsArray($page);

        $number = $page['number'] ?? null;

        return \is_scalar($number) ? (int) $number : -1;
    }

    /**
     * A document link's href, whether it rendered as a string or a link object.
     */
    private function href(mixed $link): string
    {
        if (\is_array($link) && isset($link['href']) && \is_string($link['href'])) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }
}
