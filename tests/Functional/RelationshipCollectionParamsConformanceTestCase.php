<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The queryable to-many relationship (linkage) endpoint
 * `GET /{type}/{id}/relationships/{rel}` as a real filtered/sorted/paginated
 * collection on both providers — the linkage twin of
 * {@see RelatedCollectionParamsConformanceTestCase} (bundle ADR 0096).
 *
 *  - `?sort=…` / `?filter[…]` scope against the **related** type's vocabulary merged
 *    with the relation's own scoped filters/sorts (the `comments` vocabulary, not the
 *    article's), exactly as the related endpoint does;
 *  - pagination is on by default (the relation → related resource → server-default
 *    paginator chain) and rendered as the relationship object's own
 *    `first`/`prev`/`next`(/`last`) links — the spec's home for relationship
 *    pagination — NOT a document `meta.page`;
 *  - the linkage `data` is resource IDENTIFIERS (`{type, id}`), windowed to page 1;
 *  - a previously-rejected `filter[…]` on a to-many relationship endpoint is now
 *    honoured (200-filtered), replacing the interim `400` (it reverses bundle #70);
 *    an UNKNOWN filter/sort key is still a `400`.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryRelationshipCollectionParamsTest}) and the Doctrine
 * provider ({@see DoctrineRelationshipCollectionParamsTest}); both serve the shared
 * `BaseArticleResource`/`BaseCommentResource` declarations over the shared
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures} seeds, so a
 * failure on one provider localizes the bug to that provider's execution.
 */
abstract class RelationshipCollectionParamsConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    // --- the linkage is identifiers, windowed to page 1 -----------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToManyRelationshipLinkageRendersResourceIdentifiers(): void
    {
        // The endpoint renders linkage (type/id identifiers), not full resources;
        // article 1 owns comments 1, 2, both within the default page.
        $document = $this->fetchDocument('/articles/1/relationships/comments');

        self::assertSame(
            [
                ['type' => 'comments', 'id' => '1'],
                ['type' => 'comments', 'id' => '2'],
            ],
            $this->identifiers($document),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipEndpointRendersLinkageOnlyAndNeverACompoundIncluded(): void
    {
        // A relationship endpoint returns linkage only — never a compound `included`
        // document (core D16 / core ADR 0107). `?include` is not part of its contract:
        // it is tolerated (not a 400) but inert, and no `included` member is emitted.
        // This locks the linkage-only contract on BOTH providers.
        $response = $this->handle('/articles/1/relationships/comments?include=author');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        self::assertArrayNotHasKey('included', $document);
        self::assertSame(
            [
                ['type' => 'comments', 'id' => '1'],
                ['type' => 'comments', 'id' => '2'],
            ],
            $this->identifiers($document),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    public function aToManyRelationshipLinkageSortsByTheRelatedVocabulary(): void
    {
        // sort=-body is byte-desc on the comment body: "Nice write-up." > "First!"
        // so comment 2 precedes comment 1 — sorting against the comments vocabulary.
        $document = $this->fetchDocument('/articles/1/relationships/comments?sort=-body');

        self::assertSame(['2', '1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aToManyRelationshipLinkageFiltersByTheRelatedVocabulary(): void
    {
        // filter[body] is the comments filter declared on BaseCommentResource — now
        // honoured on the relationship endpoint (it reverses bundle #70's interim 400).
        $document = $this->fetchDocument('/articles/1/relationships/comments?filter[body]=First!');

        self::assertSame(['1'], $this->ids($document));
    }

    // --- pagination as relationship-object links ------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPerRelationPaginatedRelationshipLinkageWindowsAndLinksToTheNextPage(): void
    {
        // pagedComments carries a per-relation PagePaginator: page 1 of size 1 is
        // exactly comment 1, with a `next` link (a second page exists) scoped to the
        // relationship-linkage URL. Relationship pagination rides `links`, not meta.page.
        $document = $this->fetchDocument('/articles/1/relationships/pagedComments?page[size]=1&page[number]=1');

        self::assertSame(['1'], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['next'] ?? null, 'a further page is signalled via next');
        self::assertStringContainsString('/articles/1/relationships/pagedComments', $this->href($links['next']));

        // The bare convention self stays the endpoint URL (the page self is dropped —
        // the relationship object's pagination rides first/prev/next/last).
        self::assertSame(
            self::BASE_URI . '/articles/1/relationships/pagedComments',
            $links['self'] ?? null,
        );

        // Pagination is conveyed via links, NOT a document meta.page.
        $meta = $document['meta'] ?? null;
        if (\is_array($meta)) {
            self::assertArrayNotHasKey('page', $meta, 'relationship pagination rides links, not meta.page');
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:fetching-pagination')]
    public function aPerRelationPaginatedRelationshipLinkageComposesSortThenPage(): void
    {
        // Sort desc first (comment 2, comment 1), then take the first page of size 1 → comment 2.
        $document = $this->fetchDocument('/articles/1/relationships/pagedComments?sort=-body&page[size]=1');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPlainRelationshipLinkageFallsBackToTheCappedServerDefault(): void
    {
        // The plain `comments` relation declares no paginator of its own, so the
        // linkage collection falls back to the server's default paginator (relation →
        // related resource → server default). All members render (both fit one page),
        // count-FREE: a `first` link is present but no `last`.
        $document = $this->fetchDocument('/articles/1/relationships/comments');

        self::assertSame(['1', '2'], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('first', $links);
        self::assertNull($links['last'] ?? null, 'a count-free page carries no last link');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aNonCountableRelationshipLinkageSignalsAFurtherPageWithNextNotLast(): void
    {
        // `comments` is non-countable, so a windowed page is count-free: a further page
        // is signalled by `next` (the limit+1 probe), with no `last`. Article 1 has two
        // comments; page size 1 yields exactly comment 1 with a `next` to page 2.
        $document = $this->fetchDocument('/articles/1/relationships/comments?page[size]=1&page[number]=1');

        self::assertSame(['1'], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['next'] ?? null, 'a count-free page signals more via next');
        self::assertNull($links['last'] ?? null, 'a count-free page has no last link');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aCountableRelationshipLinkageEmitsALastLinkUnderWithCountSelf(): void
    {
        // `pagedComments` is countable(), so `?withCount=_self_` (under the Countable
        // profile) counts the linkage collection — emitting a `last` link the count-free
        // default omits. `_self_` is relation-aware on a relationship render (core ADR
        // 0068 / bundle ADR 0075).
        $response = $this->handle(self::BASE_URI . '/articles/1/relationships/pagedComments?withCount=_self_&page[size]=1&page[number]=1', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        self::assertSame(['1'], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['last'] ?? null, 'a counted page emits a last link');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aCountableRelationshipLinkagePaginatesCountFreeWithoutWithCount(): void
    {
        // The G21 default: even a countable() relation paginates count-FREE until the
        // client asks — a bare windowed `pagedComments` linkage carries `next` (the
        // over-fetch probe) but no `last`.
        $document = $this->fetchDocument('/articles/1/relationships/pagedComments?page[size]=1&page[number]=1');

        self::assertSame(['1'], $this->ids($document));

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertNotNull($links['next'] ?? null, 'a further page is signalled via next');
        self::assertNull($links['last'] ?? null, 'count-free: no last link');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function withCountSelfOnANonCountableRelationshipLinkageIs400(): void
    {
        // `comments` is NOT countable(), so `?withCount=_self_` on its relationship
        // endpoint is rejected by the handler's gate — the to-many twin of the related
        // endpoint's gate (G21 §6b row 4).
        $response = $this->handle(self::BASE_URI . '/articles/1/relationships/comments?withCount=_self_', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function anOverLargePageSizeOnRelationshipLinkageIsCappedNotHonoured(): void
    {
        // The server default paginator caps page[size] at json_api.pagination
        // .max_per_page. An abusive page[size] is clamped, not honoured: both comments
        // still render and the response is 200 (the cap admits them).
        $document = $this->fetchDocument('/articles/1/relationships/comments?page[size]=1000000');

        self::assertSame(['1', '2'], $this->ids($document));
    }

    // --- relation-scoped filters and sorts (core ADR 0051) -------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aRelationScopedFilterNarrowsTheRelationshipLinkage(): void
    {
        // filter[commentBody] is declared on the `comments` RELATION (a contains-match
        // scoped to this relationship): "Nice" keeps only comment 2.
        $document = $this->fetchDocument('/articles/1/relationships/comments?filter[commentBody]=Nice');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    public function aRelationScopedSortOrdersTheRelationshipLinkage(): void
    {
        // sort=recent is declared on the `comments` RELATION (ordering by comment id),
        // scoped to this relationship. Descending puts comment 2 first; ascending
        // restores 1, 2.
        $descending = $this->fetchDocument('/articles/1/relationships/comments?sort=-recent');
        self::assertSame(['2', '1'], $this->ids($descending));

        $ascending = $this->fetchDocument('/articles/1/relationships/comments?sort=recent');
        self::assertSame(['1', '2'], $this->ids($ascending));
    }

    // --- unrecognized key still 400s (the merge does not weaken the guard) ----

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anUnrecognizedFilterStill400sOnTheRelationshipEndpoint(): void
    {
        // A key in NEITHER the related resource's vocabulary nor the relation's scoped
        // vocabulary is a 400 — exactly the case bundle #70 rejected, now reached only
        // for an UNKNOWN key (a valid key is 200-filtered above).
        $response = $this->handle(self::BASE_URI . '/articles/1/relationships/comments?filter[nope]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[nope]'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function anUnrecognizedSortStill400sOnTheRelationshipEndpoint(): void
    {
        // An unrecognized sort field on the relationship endpoint is the related
        // endpoint's same 400.
        $response = $this->handle(self::BASE_URI . '/articles/1/relationships/comments?sort=bogus');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
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
     * The linkage identifiers of the document, in document order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiers(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = ['type' => $identifier['type'] ?? null, 'id' => $identifier['id'] ?? null];
        }

        return $identifiers;
    }

    /**
     * The linkage ids of the document, in document order.
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
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
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
