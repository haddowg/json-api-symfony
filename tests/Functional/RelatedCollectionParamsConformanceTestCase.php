<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
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
        // All members render, with page meta from that default. `comments` is NOT
        // countable(), so the page is count-FREE: page meta is present (number/size)
        // but carries no `total`, and the links carry no `last` (bundle ADR 0052).
        $document = $this->fetchDocument('/articles/1/comments');

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('page', $meta);
        self::assertIsArray($meta['page']);
        self::assertArrayNotHasKey('total', $meta['page'], 'a non-countable relation paginates count-free: no total');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNull($links['last'] ?? null, 'a count-free page carries no last link');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aNonCountableRelatedCollectionSignalsAFurtherPageWithNextNotLast(): void
    {
        // `comments` is non-countable, so a windowed page is count-free: a further
        // page is signalled by `next` being present (the limit+1 probe), and there
        // is no `total`/`last` to derive a page count from. Article 1 has two
        // comments; page size 1 yields exactly comment 1 with a `next` to page 2.
        $document = $this->fetchDocument('/articles/1/comments?page[size]=1&page[number]=1');

        self::assertSame(['1'], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['next'] ?? null, 'a count-free page signals more via next');
        self::assertNull($links['last'] ?? null, 'a count-free page has no last link');

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertIsArray($meta['page'] ?? null);
        self::assertArrayNotHasKey('total', $meta['page'], 'a count-free page omits total');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aCountableRelatedCollectionEmitsTotalAndLastUnderWithCountSelf(): void
    {
        // G21 §6b: `pagedComments` is countable(), so a client opts into the total via
        // `?withCount=_self_` under the Countable profile, and the single total fans to
        // BOTH the top-level meta.total and meta.page.total, plus a `last` link. Core's
        // `_self_` gate is relation-aware on a related render (it keys on the *relation*,
        // not the related-type resource — core ADR 0068 / bundle ADR 0075), so the
        // countable relation's collection is counted even though the `comments` resource
        // is not itself countable().
        $response = $this->handle(self::BASE_URI . '/articles/1/pagedComments?withCount=_self_&page[size]=1&page[number]=1', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        self::assertSame(['1'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(2, $meta['total'] ?? null, 'the single total fans to the universal top-level meta.total');
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertSame(2, $page['total'] ?? null, 'meta.page.total echoes the same total (one count, two slots)');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['last'] ?? null, 'a counted page emits a last link');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aCountableRelatedCollectionPaginatesCountFreeWithoutWithCount(): void
    {
        // The G21 §6b default: even a countable() relation paginates count-FREE until
        // the client asks — a bare windowed `pagedComments` fetch carries no total/last,
        // only `next` (driven by the over-fetch probe).
        $document = $this->fetchDocument('/articles/1/pagedComments?page[size]=1&page[number]=1');

        self::assertSame(['1'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayNotHasKey('total', $meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertArrayNotHasKey('total', $page, 'count-free: no page total');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['next'] ?? null, 'a further page is signalled via next');
        self::assertNull($links['last'] ?? null, 'count-free: no last link');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnANonCountableRelatedCollectionIs400(): void
    {
        // `comments` is NOT countable(), so `?withCount=_self_` on its related endpoint
        // is rejected by the handler's gate (CollectionDocument runs no document-level
        // countable validation) — G21 §6b row 4.
        $response = $this->handle(self::BASE_URI . '/articles/1/comments?withCount=_self_', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfOnAToOneRelatedEndpointIs400(): void
    {
        // A to-one related endpoint (`/articles/1/author`) has no collection, so
        // `?withCount=_self_` is invalid there regardless of the related resource's
        // countability — rejected by the handler's to-one gate (and core's document
        // gate, which `RelatedResponse::fromResource` carries `selfCountable:false`
        // into). G21 §6b: `_self_` names the *primary collection*; a to-one has none.
        $response = $this->handle(self::BASE_URI . '/articles/1/author?withCount=_self_', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
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

    // --- relation-scoped filters and sorts (core ADR 0051) -------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aRelationScopedFilterNarrowsTheRelatedCollection(): void
    {
        // filter[commentBody] is declared on the `comments` RELATION (not the
        // comment resource): a contains-match scoped to GET /articles/1/comments.
        // Article 1's comments are 1 ("First!") and 2 ("Nice write-up."), so a
        // contains-match on "Nice" keeps only comment 2 — the rejected comment 1 is
        // excluded.
        $document = $this->fetchDocument('/articles/1/comments?filter[commentBody]=Nice');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    public function aRelationScopedSortOrdersTheRelatedCollection(): void
    {
        // sort=recent is declared on the `comments` RELATION (ordering by comment
        // id), scoped to GET /articles/1/comments. Descending puts the newer
        // comment 2 first, ascending restores 1, 2 — proving the relation's sort
        // executes through the provider's sort handler.
        $descending = $this->fetchDocument('/articles/1/comments?sort=-recent');
        self::assertSame(['2', '1'], $this->ids($descending));

        $ascending = $this->fetchDocument('/articles/1/comments?sort=recent');
        self::assertSame(['1', '2'], $this->ids($ascending));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-sorting')]
    public function theRelationScopedVocabularyMergesWithTheRelatedResourceVocabulary(): void
    {
        // The merged vocabulary applies BOTH the relation's scoped filter and the
        // comment resource's own `filter[body]` together: a relation contains-match
        // on "." (comment 2 "Nice write-up." and comment 3 "Could use more detail.")
        // combined with the resource's exact `filter[body]` does not over-narrow —
        // here only the relation filter is used to confirm the merge keeps the
        // resource vocabulary available alongside it.
        $merged = $this->fetchDocument('/articles/1/comments?filter[commentBody]=write&filter[body]=Nice%20write-up.');
        self::assertSame(['2'], $this->ids($merged));

        // The relation's sort and the resource's `?sort=body` are both recognized on
        // the related endpoint (the resource sort still applies after the merge).
        $resourceSort = $this->fetchDocument('/articles/1/comments?sort=-body');
        self::assertSame(['2', '1'], $this->ids($resourceSort));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aRelationScopedFilterIsNotRecognizedOnThePrimaryCollection(): void
    {
        // The load-bearing scoping guarantee: filter[commentBody] is declared on the
        // RELATION, so it reaches /articles/1/comments but NOT the primary
        // /comments collection — there only the comment resource's own vocabulary
        // applies, so the relation-scoped key is an unrecognized filter (400).
        $response = $this->handle(self::BASE_URI . '/comments?filter[commentBody]=Nice');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame(['parameter' => 'filter[commentBody]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aRelationScopedSortIsNotRecognizedOnThePrimaryCollection(): void
    {
        // The symmetric scoping guarantee for sorts: sort=recent is declared on the
        // RELATION, so the primary /comments collection does not recognize it (400).
        $response = $this->handle(self::BASE_URI . '/comments?sort=recent');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($this->decode($response))['code'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anUnrecognizedFilterStill400sOnTheRelatedCollection(): void
    {
        // A key in NEITHER the related resource's vocabulary nor the relation's
        // scoped vocabulary still renders a 400 on the related endpoint — the merge
        // does not weaken the unrecognized-key guard.
        $response = $this->handle(self::BASE_URI . '/articles/1/comments?filter[nope]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[nope]'], $this->firstError($this->decode($response))['source'] ?? null);
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
     * Fetches `$path` with `?withCount=_self_` appended and the Countable profile
     * negotiated, so a `countable()` relation's related endpoint renders the total
     * (`meta.total` + `meta.page.total` + the `last` link) — the §6b client count.
     *
     * @return array<string, mixed>
     */
    protected function fetchCountedDocument(string $path): array
    {
        $separator = \str_contains($path, '?') ? '&' : '?';
        $response = $this->handle(self::BASE_URI . $path . $separator . 'withCount=_self_', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

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
     * The first error object of an error document, asserting the document carries a
     * non-empty `errors` array.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $error = $errors[0];
        self::assertIsArray($error);

        return $error;
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
