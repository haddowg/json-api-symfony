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
 *  - a plain relation (`comments`) stays unpaginated (the regression baseline so
 *    the rework doesn't accidentally paginate every related collection).
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
        // so c2 precedes c1 — sorting against the comments vocabulary, not articles.
        $document = $this->fetchDocument('/articles/1/comments?sort=-body');

        self::assertSame(['c2', 'c1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aRelatedToManyCollectionFiltersByTheRelatedVocabulary(): void
    {
        // filter[body] is the comments filter declared on BaseCommentResource.
        $document = $this->fetchDocument('/articles/1/comments?filter[body]=First!');

        self::assertSame(['c1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPerRelationPaginatedRelatedCollectionWindowsAndCarriesPageMeta(): void
    {
        // pagedComments carries a per-relation PagePaginator: page 1 of size 1 is
        // exactly c1, with page meta and navigation links scoped to the request path.
        $document = $this->fetchDocument('/articles/1/pagedComments?page[size]=1&page[number]=1');

        self::assertSame(['c1'], $this->ids($document));

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
        // Sort desc first (c2, c1), then take the first page of size 1 → c2.
        $document = $this->fetchDocument('/articles/1/pagedComments?sort=-body&page[size]=1');

        self::assertSame(['c2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPlainRelatedToManyCollectionIsUnpaginated(): void
    {
        // The plain `comments` relation declares no paginator, so the related
        // collection renders all members with NO page meta — the unpaginated
        // baseline the rework must preserve.
        $document = $this->fetchDocument('/articles/1/comments');

        self::assertSame(['c1', 'c2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        if (\is_array($meta)) {
            self::assertArrayNotHasKey('page', $meta);
        }
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
