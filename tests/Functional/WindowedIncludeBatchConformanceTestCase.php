<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The acceptance suite for the bounded ROW_NUMBER windowed-include batch (bundle ADR
 * 0065), run identically against the in-memory witness
 * ({@see InMemoryWindowedIncludeBatchTest}), the Doctrine native path with
 * `json_api.doctrine.window_functions: true` ({@see DoctrineWindowedIncludeBatchOnTest}),
 * and the Doctrine per-parent fallback with it `false`
 * ({@see DoctrineWindowedIncludeBatchOffTest}) — so the native batch and the fallback are
 * both proven byte-identical to the witness.
 *
 * The seed (one source: {@see WindowedSeedData}) is a LARGE windowed relation — article 1
 * with 50 comments — so a page-1 windowed include must be BOUNDED (the headline 6a fix:
 * it materialised every parent's whole set then sliced in PHP) and carry the REAL total
 * (50), not the page size. It covers both native shapes (the inverse-FK `pinnedComments`
 * and the m2m `editors`), a countable and a non-countable relation, ties, and a filtered
 * windowed include (the pragmatic split's per-parent boundary).
 */
abstract class WindowedIncludeBatchConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const string BASE_URI = 'https://example.test';

    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    /** The server-default page size the windowed include slices to (PagePaginator). */
    private const int PAGE_SIZE = 15;

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aWindowedInverseFkIncludeIsBoundedAndCarriesTheRealTotal(): void
    {
        // GET /articles including pinnedComments (a countable inverse-FK to-many) windowed
        // by -body. Article 1 has 50 comments comment-00..comment-49, so the page-1 window
        // is the 15 NEWEST (comment-49..comment-35) — bounded, and the relationship total
        // is the REAL 50, not the 15-row page size (the headline 6a bug).
        $document = $this->profileDocument('/articles?include=pinnedComments&relatedQuery[pinnedComments][sort]=-body');

        $article1 = $this->resource($document, '1');
        $linkage = $this->linkageIds($article1, 'pinnedComments');

        self::assertCount(self::PAGE_SIZE, $linkage, 'the window is bounded to the page size, not the whole 50-comment set');
        self::assertSame($this->expectedDescBodyIds(50, self::PAGE_SIZE), $linkage, 'the 15 newest comments by -body land on page 1');

        // The REAL total (50) drives the countable relationship object's `last` link:
        // ceil(50 / 15) = page 4 — NOT a page-size-derived total (the headline 6a bug,
        // which would have pointed `last` at page 1 over the 15-row over-fetch).
        $links = $this->relationshipLinks($article1, 'pinnedComments');
        self::assertNotNull($links['last'] ?? null, 'a countable relation emits a last link');
        self::assertSame('4', $this->pageNumber($this->href($links['last'])), 'the last page reflects the REAL total of 50 (ceil(50/15) = 4)');
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aWindowedManyToManyIncludeHydratesAndScopesPerParent(): void
    {
        // The m2m `editors` shape: article 1 has both editors (Ada=1, Grace=2), article 2
        // has Ada only. Windowed by name asc, each parent's editors are scoped without
        // cross-parent bleed (the native join-table partition / the fallback subquery).
        $document = $this->profileDocument('/articles?include=editors&relatedQuery[editors][sort]=name');

        self::assertSame(['1', '2'], $this->linkageIds($this->resource($document, '1'), 'editors'));
        self::assertSame(['1'], $this->linkageIds($this->resource($document, '2'), 'editors'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-sorting')]
    public function bothShapesWindowCorrectlyInOneRequest(): void
    {
        // An inverse-FK AND an m2m relation windowed in the SAME request — the partition-
        // by-FK and partition-by-join-column native SQL both hydrate, side by side.
        $document = $this->profileDocument(
            '/articles?include=editors,pinnedComments'
            . '&relatedQuery[editors][sort]=name&relatedQuery[pinnedComments][sort]=-body',
        );

        $article1 = $this->resource($document, '1');
        self::assertSame(['1', '2'], $this->linkageIds($article1, 'editors'));
        self::assertSame($this->expectedDescBodyIds(50, self::PAGE_SIZE), $this->linkageIds($article1, 'pinnedComments'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-sorting')]
    public function aWindowedIncludeIsDeterministicOnTies(): void
    {
        // Article 3's two comments both have body `tie` (ids 54, 55), so they tie on the
        // sort column. Both providers resolve the tie by the PK tiebreak (ascending), so
        // the order is 54 then 55 — not insertion-order-dependent. This is the assertion
        // that forces the PK tiebreak into BOTH providers.
        $document = $this->profileDocument('/articles?include=pinnedComments&relatedQuery[pinnedComments][sort]=body');

        self::assertSame(['54', '55'], $this->linkageIds($this->resource($document, '3'), 'pinnedComments'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aFilteredWindowedIncludeIsBoundedAndCarriesTheFilteredTotal(): void
    {
        // A windowed include WITH a relatedQuery filter — the pragmatic split's per-parent
        // boundary (the native path routes a filtered windowed include to the bounded
        // fallback). filter[bodyContains]=comment-4 is a LIKE match selecting
        // comment-40..comment-49 (10 comments), ordered -body -> comment-49..comment-40 on
        // a single page, and the relationship total is the FILTERED cardinality (10).
        $document = $this->profileDocument(
            '/articles?include=pinnedComments'
            . '&relatedQuery[pinnedComments][filter][bodyContains]=comment-4&relatedQuery[pinnedComments][sort]=-body',
        );

        $article1 = $this->resource($document, '1');
        $linkage = $this->linkageIds($article1, 'pinnedComments');

        self::assertSame(['50', '49', '48', '47', '46', '45', '44', '43', '42', '41'], $linkage, 'the 10 filtered comments (comment-49..comment-40), ordered -body');

        // The filtered window fits one page (10 <= 15), so the `last` link points at page 1
        // — the FILTERED cardinality drives pagination, not the unfiltered 50.
        $links = $this->relationshipLinks($article1, 'pinnedComments');
        self::assertSame('1', $this->pageNumber($this->href($links['last'] ?? '')), 'the filtered windowed total (10) fits one page');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aNonCountableWindowedIncludeEmitsNoTotalAndACountFreeNext(): void
    {
        // lazyComments is a NON-countable to-many backed by featuredComments (article 1's
        // 50 featured comments). Its windowed include emits NO total and a count-free next
        // link driven by hasMore (50 > 15), on both the native and fallback paths.
        $document = $this->profileDocument('/articles/1?include=lazyComments');

        $article1 = $document['data'] ?? null;
        $relationship = $this->relationshipObjectOf($article1, 'lazyComments');
        $links = $this->relationshipLinks($article1, 'lazyComments');

        // Count-free: no last link, a next driven by hasMore (50 > 15), and NO total
        // anywhere on the relationship object (the count-free contract).
        self::assertArrayNotHasKey('last', $links, 'a non-countable relation emits no last link');
        self::assertNotNull($links['next'] ?? null, 'a further page exists (50 > 15), so a count-free next is emitted');
        self::assertArrayNotHasKey('meta', $relationship, 'a non-countable windowed include emits no total (no meta)');
    }

    // --- request helpers -------------------------------------------------------

    protected function profileRequest(string $path): Response
    {
        return $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function profileDocument(string $path): array
    {
        $response = $this->profileRequest($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    // --- assertion helpers -----------------------------------------------------

    /**
     * The expected linkage ids of the N newest (highest body) of `$total` comments under a
     * `-body` sort, limited to `$limit`. The bodies are `comment-00`..`comment-(total-1)`
     * with PK == position+1, so the descending-body order is ids `total`..`total-limit+1`.
     *
     * @return list<string>
     */
    private function expectedDescBodyIds(int $total, int $limit): array
    {
        $ids = [];
        for ($i = 0; $i < $limit; $i++) {
            $ids[] = (string) ($total - $i);
        }

        return $ids;
    }

    /**
     * The primary-collection resource with `$id`.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function resource(array $document, string $id): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        foreach ($data as $resource) {
            self::assertIsArray($resource);
            if (($resource['id'] ?? null) === $id) {
                /** @var array<string, mixed> $resource */
                return $resource;
            }
        }

        self::fail(\sprintf('article "%s" is not in the document', $id));
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return list<string>
     */
    private function linkageIds(array $resource, string $relationship): array
    {
        $relationshipObject = $this->relationshipObject($resource, $relationship);

        $data = $relationshipObject['data'] ?? null;
        self::assertIsArray($data, \sprintf('relationship "%s" carries linkage data', $relationship));

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The named relationship object of a resource that may be a mixed document `data`.
     *
     * @param mixed $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipObjectOf(mixed $resource, string $relationship): array
    {
        self::assertIsArray($resource);

        return $this->relationshipObject($resource, $relationship);
    }

    /**
     * @param mixed $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipLinks(mixed $resource, string $relationship): array
    {
        $relationshipObject = $this->relationshipObjectOf($resource, $relationship);

        $links = $relationshipObject['links'] ?? [];
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        return $links;
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipObject(array $resource, string $relationship): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));

        /** @var array<string, mixed> $relationshipObject */
        return $relationshipObject;
    }

    /**
     * A link's href, whether it rendered as a string or a link object.
     */
    private function href(mixed $link): string
    {
        if (\is_array($link) && \is_string($link['href'] ?? null)) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }

    /**
     * The `page[number]` of a pagination link's URL — the page count encodes the REAL
     * (or filtered) total, so it is the witness that the windowed include carries the
     * true cardinality rather than the page size.
     */
    private function pageNumber(string $href): string
    {
        $query = \parse_url($href, \PHP_URL_QUERY);
        self::assertIsString($query, \sprintf('"%s" carries a query string', $href));

        \parse_str($query, $params);
        $page = $params['page'] ?? null;
        self::assertIsArray($page);
        $number = $page['number'] ?? null;
        self::assertIsString($number);

        return $number;
    }
}
