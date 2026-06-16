<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-4 P7 acceptance suite: the related to-many endpoint
 * `GET /{type}/{id}/{relationship}` as a real queryable, paginated collection on
 * both providers.
 *
 *  - `?sort=…` / `?filter[…]` scope against the **related** type's vocabulary (the
 *    `comments` filters/sorts, not the article's);
 *  - a relation carrying a per-relation paginator (`pagedComments`) windows by
 *    `page[number]`/`page[size]` and emits page `meta`/`links` scoped to the
 *    related-collection URL;
 *  - a plain relation (`comments`) with no paginator of its own falls back to the
 *    server's default paginator (relation → related resource → server default),
 *    which carries the page-size cap (`json_api.pagination.max_per_page`).
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryRelatedCollectionParamsTest}) and the Doctrine provider
 * ({@see DoctrineRelatedCollectionParamsTest}); both serve the shared
 * `BaseArticleResource`/`BaseCommentResource` declarations over the shared
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures} seeds, so a
 * failure on one provider localizes the bug to that provider's execution.
 */
abstract class RelatedCollectionParamsConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    public function aRelatedToManyCollectionSortsByTheRelatedVocabulary(): void
    {
        // sort=-body is byte-desc on the comment body: "Nice write-up." > "First!"
        // so comment 2 precedes comment 1 — sorting against the comments vocabulary, not articles.
        $document = $this->fetchDocument('/articles/1/comments?sort=-body');

        self::assertSame(['2', '1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aRelatedToManyCollectionFiltersByTheRelatedVocabulary(): void
    {
        // filter[body] is the comments filter declared on BaseCommentResource.
        $document = $this->fetchDocument('/articles/1/comments?filter[body]=First!');

        self::assertSame(['1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPerRelationPaginatedRelatedCollectionWindowsAndCarriesPageMeta(): void
    {
        // pagedComments carries a per-relation PagePaginator: page 1 of size 1 is
        // exactly comment 1, with page meta and navigation links scoped to the request path.
        $document = $this->fetchDocument('/articles/1/pagedComments?page[size]=1&page[number]=1');

        self::assertSame(['1'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('page', $meta);
        self::assertIsArray($meta['page']);

        $links = $document['links'] ?? null;
        self::assertIsArray($links);

        // A second page exists (two comments, size 1), so next/last are present.
        $next = $links['next'] ?? null;
        $last = $links['last'] ?? null;
        self::assertTrue($next !== null || $last !== null);

        // Page links are scoped to the related-collection URL the client hit.
        self::assertStringContainsString('/articles/1/pagedComments', $this->href($last ?? $next));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:fetching-pagination')]
    public function aPerRelationPaginatedRelatedCollectionComposesSortThenPage(): void
    {
        // Sort desc first (comment 2, comment 1), then take the first page of size 1 → comment 2.
        $document = $this->fetchDocument('/articles/1/pagedComments?sort=-body&page[size]=1');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPlainRelatedToManyCollectionFallsBackToTheCappedServerDefault(): void
    {
        // The plain `comments` relation declares no paginator of its own, so the
        // related collection falls back to the server's default paginator (relation
        // → related resource → server default) — which carries the page-size cap.
        // All members render, now with page meta from that default.
        $document = $this->fetchDocument('/articles/1/comments');

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('page', $meta);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function anOverLargePageSizeOnARelatedCollectionIsCappedNotHonoured(): void
    {
        // The server default paginator caps page[size] at json_api.pagination
        // .max_per_page (100 by default). An abusive page[size] is clamped, not
        // honoured: meta.page.perPage reflects the cap and the response is 200.
        $document = $this->fetchDocument('/articles/1/comments?page[size]=1000000');

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertSame(100, $page['perPage'] ?? null, 'page[size] is clamped to the cap, not 1000000');
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * The ids of the document's primary (related) data, in document order.
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
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
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
