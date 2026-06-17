<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipCountsProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Slice-1 acceptance suite for countable relations + `?withCount` (bundle ADR
 * 0052, core ADR 0057), run identically against the in-memory provider
 * ({@see InMemoryRelationCountTest}) and the Doctrine provider
 * ({@see DoctrineRelationCountTest}).
 *
 * `pagedComments` and `editors` are declared `countable()` on the shared
 * {@see App\Resource\BaseArticleResource}; `comments` deliberately is not (the
 * count-free witness exercised by {@see RelatedCollectionParamsConformanceTestCase}).
 * The article fixtures seed article 1 with two comments and two editors, article 2
 * with one of each, article 3 with two comments and one editor — so a batched
 * collection count is observably per-parent, not a single repeated value.
 *
 *  - `?withCount=pagedComments` on a single article emits `meta.total` on that
 *    relationship object;
 *  - `?withCount=pagedComments,editors` on the whole `/articles` collection emits
 *    each parent's per-relationship `meta.total` — counted in ONE grouped query per
 *    relation across the page (the Doctrine subclass adds a query-count probe);
 *  - a `?withCount` naming a non-countable relation (`comments`) or a to-one
 *    (`author`) is a `400` (core validates up front against the primary
 *    serializer's countable set);
 *  - a relationship NOT named in `?withCount` carries no `meta.total`, even though
 *    it is countable (the relationship-object total is gated by `?withCount`);
 *  - a `?withCount`-named relation that also carries a `relatedQuery[<rel>][filter]`
 *    counts its FILTERED set (bundle ADR 0060): the count matches the related
 *    endpoint's filtered `meta.page.total`, a parent with no matching member reports
 *    `0` (zero-filled on Doctrine, the empty filtered set in memory), and the filter
 *    narrows each parent independently across a collection — while the common
 *    no-relatedQuery count stays raw membership unchanged.
 */
abstract class RelationCountConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    // `?withCount` is gated behind the Relationship Counts profile, so every count
    // fetch negotiates it.
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipCountsProfile::URI . '"';

    // The D5 filtered-count tests carry BOTH `?withCount` and a `relatedQuery[<rel>]`
    // filter, so they negotiate the Relationship Counts and Relationship Queries
    // profiles together (a space-separated list in the one `profile` parameter).
    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipCountsProfile::URI . ' ' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountEmitsTheRelationshipObjectTotalOnASingleResource(): void
    {
        $document = $this->fetchDocument('/articles/1?withCount=pagedComments');

        self::assertSame(2, $this->relationshipTotal($document['data'] ?? null, 'pagedComments'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountBatchesTheRelationshipObjectTotalAcrossACollection(): void
    {
        // Each parent's own count — proving the batch is per-parent, not one value.
        $document = $this->fetchDocument('/articles?withCount=pagedComments,editors');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        // Article 1: 2 comments, 2 editors; article 2: 1 comment, 1 editor;
        // article 3: 2 comments, 1 editor (per-parent, proving the batch is not one
        // repeated value).
        $expected = ['1' => [2, 2], '2' => [1, 1], '3' => [2, 1]];
        $seen = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            if (!\is_string($id) || !isset($expected[$id])) {
                continue;
            }

            [$comments, $editors] = $expected[$id];
            self::assertSame($comments, $this->relationshipTotal($resource, 'pagedComments'), \sprintf('article "%s" pagedComments total', $id));
            self::assertSame($editors, $this->relationshipTotal($resource, 'editors'), \sprintf('article "%s" editors total', $id));
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'all expected articles were counted');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipNotNamedInWithCountCarriesNoTotal(): void
    {
        // editors is countable() but not named, so it carries no meta.total — the
        // relationship-object total is gated by ?withCount, not by countable() alone.
        $document = $this->fetchDocument('/articles/1?withCount=pagedComments');

        $relationships = $this->relationships($document['data'] ?? null);
        $editors = $relationships['editors'] ?? null;
        self::assertIsArray($editors);
        self::assertArrayNotHasKey('meta', $editors, 'an unnamed countable relation carries no total');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:errors')]
    public function aNonCountableRelationInWithCountIs400(): void
    {
        // `comments` is a to-many but not countable(): core rejects it up front
        // against the primary serializer's countable set (source.parameter withCount).
        $response = $this->handle(self::BASE_URI . '/articles/1?withCount=comments', extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:errors')]
    public function aToOneRelationInWithCountIs400(): void
    {
        // `author` is a to-one — counting is a to-many concern, so it is never in the
        // countable set and ?withCount=author is a 400.
        $response = $this->handle(self::BASE_URI . '/articles/1?withCount=author', extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:errors')]
    public function anUnknownRelationInWithCountIs400(): void
    {
        $response = $this->handle(self::BASE_URI . '/articles/1?withCount=nope', extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:errors')]
    public function withCountIsUnrecognizedWhenTheRelationshipCountsProfileIsNotNegotiated(): void
    {
        // `?withCount` is gated behind the Relationship Counts profile: without it
        // negotiated, the family is unrecognized and strict validation rejects it.
        $response = $this->handle(self::BASE_URI . '/articles/1?withCount=editors');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    // --- D5: ?withCount honours the relation's active filters (bundle ADR 0060) --

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountReflectsTheRelationsRelatedQueryFilterAndMatchesTheEndpointTotal(): void
    {
        // editors is a countable many-to-many to authors. Article 1's editors are
        // Ada Lovelace (1) and Grace Hopper (2); the relatedQuery filter[name]=Grace
        // Hopper narrows the counted set to one (bundle ADR 0060) — the SAME set the
        // related endpoint pages, not raw membership (which is 2).
        $document = $this->profileFetchDocument('/articles/1?withCount=editors&relatedQuery[editors][filter][name]=Grace%20Hopper');

        self::assertSame(1, $this->relationshipTotal($document['data'] ?? null, 'editors'));

        // The related-collection ENDPOINT reports the same filtered total for the
        // same relation/parent — one consistent `total` semantic.
        self::assertSame(1, $this->endpointTotal('/articles/1/editors?filter[name]=Grace%20Hopper'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountReportsZeroForAParentWithNoMemberMatchingTheRelatedQueryFilter(): void
    {
        // Article 2's only editor is Ada Lovelace (1); filtering editors to Grace
        // Hopper leaves none, so the count is 0 (the Doctrine provider zero-fills the
        // dropped parent; the in-memory witness counts the empty filtered set) — and
        // the endpoint pages an empty set with total 0.
        $document = $this->profileFetchDocument('/articles/2?withCount=editors&relatedQuery[editors][filter][name]=Grace%20Hopper');

        self::assertSame(0, $this->relationshipTotal($document['data'] ?? null, 'editors'));
        self::assertSame(0, $this->endpointTotal('/articles/2/editors?filter[name]=Grace%20Hopper'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountFiltersPerParentAcrossACollection(): void
    {
        // The whole /articles collection, ?withCount=editors with the same Grace
        // Hopper filter. Article 1 (Ada + Grace) -> 1, article 2 (Ada) -> 0, article 3
        // (Grace) -> 1: the filter narrows each parent independently, and a
        // zero-match parent reports 0 rather than dropping out.
        $document = $this->profileFetchDocument('/articles?withCount=editors&relatedQuery[editors][filter][name]=Grace%20Hopper');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $expected = ['1' => 1, '2' => 0, '3' => 1];
        $seen = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            if (!\is_string($id) || !isset($expected[$id])) {
                continue;
            }

            self::assertSame($expected[$id], $this->relationshipTotal($resource, 'editors'), \sprintf('article "%s" editors total', $id));
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected article was counted');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountIsRawMembershipWhenTheRelationHasNoRelatedQueryFilter(): void
    {
        // The common case: no relatedQuery filter, so the count is unchanged raw
        // membership — article 1 has two editors, exactly as before D5 (bundle ADR
        // 0060). This pins the "no-filter case unchanged" half of the behaviour.
        //
        // `editors` -> `authors`, and the authors resource declares a defaultSort() on
        // `name` (a column on the AUTHOR entity, not the article parent). A count needs
        // no order and roots on the parent, so the related resource's default order MUST
        // be dropped from the count criteria — otherwise the Doctrine grouped count would
        // emit `ORDER BY parent.name` (a related-entity column against the parent root)
        // and the SQL engine would reject it, diverging from the in-memory witness which
        // never crashes. This case therefore doubles as the regression witness: it runs
        // against the count path of a related resource that DOES declare a defaultSort,
        // and a raw membership of 2 on BOTH providers proves the default order is dropped
        // crash-free, not silently applied (bundle ADR 0060).
        $document = $this->fetchDocument('/articles/1?withCount=editors');

        self::assertSame(2, $this->relationshipTotal($document['data'] ?? null, 'editors'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountDropsTheRelatedResourcesDefaultSortAcrossACollection(): void
    {
        // The collection regression witness: ?withCount=editors over the whole
        // /articles collection, where the `authors` related resource declares a
        // defaultSort() on `name`. The Doctrine count roots on the article parent and
        // groups, so an un-dropped related default order would emit `ORDER BY
        // parent.name` on a grouped aggregate over a column the parent does not have —
        // a hard SQL error. Dropping the default order keeps the count crash-free and
        // byte-identical to the in-memory witness's per-parent membership (bundle ADR
        // 0060): article 1 -> 2, article 2 -> 1, article 3 -> 1.
        $document = $this->fetchDocument('/articles?withCount=editors');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $expected = ['1' => 2, '2' => 1, '3' => 1];
        $seen = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            if (!\is_string($id) || !isset($expected[$id])) {
                continue;
            }

            self::assertSame($expected[$id], $this->relationshipTotal($resource, 'editors'), \sprintf('article "%s" editors total', $id));
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected article was counted');
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * The related-collection endpoint's pagination `meta.page.total` for the given
     * path — the filtered total a `?withCount` count must agree with.
     */
    protected function endpointTotal(string $path): int
    {
        $document = $this->fetchDocument($path);

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        $total = $page['total'] ?? null;
        self::assertIsInt($total, 'the related endpoint emits an integer meta.page.total');

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * A profile-negotiated fetch: the `Accept` carries the Relationship Queries profile
     * URI so the `relatedQuery`/`rQ` family parses (without it strict query-parameter
     * validation rejects the family with a 400). Used by the D5 filtered-count tests,
     * which address a relation's filter through `relatedQuery[<rel>][filter]`.
     *
     * @return array<string, mixed>
     */
    protected function profileFetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The `meta.total` of a resource's named relationship object, asserting it is an
     * int (so a missing total fails loudly rather than returning null).
     */
    protected function relationshipTotal(mixed $resource, string $name): int
    {
        $relationship = $this->relationships($resource)[$name] ?? null;
        self::assertIsArray($relationship, \sprintf('relationship "%s" is present', $name));

        $meta = $relationship['meta'] ?? null;
        self::assertIsArray($meta, \sprintf('relationship "%s" carries meta', $name));

        $total = $meta['total'] ?? null;
        self::assertIsInt($total, \sprintf('relationship "%s" meta.total is an int', $name));

        return $total;
    }

    /**
     * The `relationships` member of a resource object.
     *
     * @return array<string, mixed>
     */
    private function relationships(mixed $resource): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, mixed> $relationships */
        return $relationships;
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

        $error = $errors[0];
        self::assertIsArray($error);

        return $error;
    }
}
