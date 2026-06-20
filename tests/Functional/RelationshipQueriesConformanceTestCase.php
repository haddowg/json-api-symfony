<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Slice-2 acceptance suite for the Relationship Queries profile (bundle ADR
 * 0053, core ADR 0058), run identically against the in-memory provider
 * ({@see InMemoryRelationshipQueriesTest}) and the Doctrine provider
 * ({@see DoctrineRelationshipQueriesTest}).
 *
 * A client negotiates the profile (its URI in the `Accept` `profile` media-type
 * parameter) and addresses a relationship's linkage from the PRIMARY request via
 * the `relatedQuery` / `rQ` family, keyed by the relationship's include path:
 *
 *  - `relatedQuery[<rel>][sort]=-field` orders the relationship's linkage `data`
 *    (and so SELECTS which members land on the always-page-1 include); the included
 *    resources reflect that page;
 *  - `relatedQuery[<rel>][filter][<key>]=…` narrows the relationship's set against
 *    the related-collection ENDPOINT's vocabulary (the related resource's filters
 *    merged with the relation's own scoped filters); an unknown key is the
 *    endpoint's same `400`;
 *  - `rQ` is an identical shorthand, and on a `[path][op]` conflict the canonical
 *    `relatedQuery` wins (the shorthand yields, never a `400`);
 *  - a rendered to-many relationship object carries its pagination links
 *    (`first`/`prev`/`next` + `last` only when the relation is `countable()`) in the
 *    spec's PLAIN form against the relationship-linkage endpoint, mirroring the
 *    relatedQuery sort/filter — never the profile's `relatedQuery[…]` form;
 *  - the params are IGNORED entirely (today's behaviour, no profile advertised)
 *    when the client did not negotiate the profile.
 *
 * The shared {@see App\Resource\BaseArticleResource} declares `editors` (a distinct
 * many-to-many to `authors`, sortable/filterable by `name`, `countable()`) and
 * `lazyComments` (a distinct non-countable to-many to `comments`) on distinct backing
 * columns. The shared-column relations (`comments`/`pagedComments`/`lockedComments` over
 * one association) exercise the bystander-isolation guarantee: windowing one (the
 * `withData` `comments`) supplies its page to core OUT-OF-BAND (the relationship-linkage
 * seam) rather than writing it onto the shared property, so a sibling relation reading
 * the same column renders its OWN membership — asserted by
 * {@see windowingOneSharedColumnRelationDoesNotAlterASiblingBystandersLinkage()}
 * (bundle ADR 0086).
 */
abstract class RelationshipQueriesConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-sorting')]
    public function relatedQuerySortOrdersAnIncludedRelationshipsLinkageAndIncluded(): void
    {
        // Article 1's editors are Ada Lovelace (1) and Grace Hopper (2). sort=-name
        // is byte-desc on the author name, so Grace (2) precedes Ada (1). The linkage
        // `data` AND the included resources reflect that page-1 order.
        $document = $this->profileDocument('/articles/1?include=editors&relatedQuery[editors][sort]=-name');

        self::assertSame(['2', '1'], $this->linkageIds($document, 'editors'));
        self::assertSame(['2', '1'], $this->includedIds($document, 'authors'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function relatedQueryFilterNarrowsAnIncludedRelationshipsLinkage(): void
    {
        // filter[name] is the related authors vocabulary, addressed through the
        // editors relationship: only Grace Hopper matches, so the linkage narrows.
        $document = $this->profileDocument('/articles/1?include=editors&relatedQuery[editors][filter][name]=Grace%20Hopper');

        self::assertSame(['2'], $this->linkageIds($document, 'editors'));
        self::assertSame(['2'], $this->includedIds($document, 'authors'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-sorting')]
    public function relatedQuerySortAppliesPerParentOnACollectionInclude(): void
    {
        // GET /articles?include=editors with a per-relationship sort: each parent's
        // editors are windowed to page 1 ordered by -name independently. Article 1
        // has editors [1,2] -> [2,1]; article 2 has [1]; article 3 has [2].
        $document = $this->profileDocument('/articles?include=editors&relatedQuery[editors][sort]=-name');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $expected = ['1' => ['2', '1'], '2' => ['1'], '3' => ['2']];
        $seen = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            if (!isset($expected[$id])) {
                continue;
            }
            self::assertSame(
                $expected[$id],
                $this->linkageIds($resource, 'editors'),
                \sprintf('article "%s" editors windowed to page 1 ordered -name per parent', $id),
            );
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected article was windowed');
    }

    #[Test]
    #[Group('spec:profiles')]
    public function theRqShorthandIsIdenticalToTheCanonicalFamily(): void
    {
        $canonical = $this->profileDocument('/articles/1?include=editors&relatedQuery[editors][sort]=-name');
        $shorthand = $this->profileDocument('/articles/1?include=editors&rQ[editors][sort]=-name');

        self::assertSame(
            $this->linkageIds($canonical, 'editors'),
            $this->linkageIds($shorthand, 'editors'),
            'rQ yields the same linkage order as relatedQuery',
        );
        self::assertSame(['2', '1'], $this->linkageIds($shorthand, 'editors'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function onAConflictTheCanonicalRelatedQueryWinsOverTheRqShorthand(): void
    {
        // Both families target editors[sort] with opposite directions; the canonical
        // relatedQuery (asc -> [1,2]) wins over rQ (-name -> [2,1]).
        $document = $this->profileDocument(
            '/articles/1?include=editors&rQ[editors][sort]=-name&relatedQuery[editors][sort]=name',
        );

        self::assertSame(['1', '2'], $this->linkageIds($document, 'editors'), 'canonical relatedQuery wins the conflict');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function anUnknownFilterKeyUnderTheProfileIs400(): void
    {
        $response = $this->profileRequest('/articles/1?relatedQuery[editors][filter][nope]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[nope]'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function anUnknownSortKeyUnderTheProfileIs400(): void
    {
        $response = $this->profileRequest('/articles/1?relatedQuery[editors][sort]=nope');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($this->decode($response))['code'] ?? null);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function anUnknownRelationshipPathUnderTheProfileIs400(): void
    {
        // No `bogusRel` relation exists on articles: the path resolves to nothing, so
        // (per the locked spec rule) it is the related-collection endpoint's same
        // `400`, with `source.parameter` naming the offending profile param as the
        // client wrote it — never a silent 200 that masks a client typo.
        $response = $this->profileRequest('/articles/1?relatedQuery[bogusRel][sort]=name');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['parameter' => 'relatedQuery[bogusRel]'],
            $this->firstError($this->decode($response))['source'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function anUnknownRelationshipPathViaTheShorthandReportsTheShorthand(): void
    {
        // The error names the parameter as the client wrote it: addressing the unknown
        // path through the `rQ` shorthand reports `rQ[bogusRel]`, not a normalised
        // canonical `relatedQuery[bogusRel]`.
        $response = $this->profileRequest('/articles/1?rQ[bogusRel][sort]=name');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['parameter' => 'rQ[bogusRel]'],
            $this->firstError($this->decode($response))['source'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function aToOneRelationshipPathWithSortUnderTheProfileIs400(): void
    {
        // `author` is a to-one (BelongsTo) relation: addressing it with a [sort] op is
        // the `400` (a single member has nothing to order), with the offending canonical
        // profile param in `source.parameter`. [filter] on a to-one is allowed (ADR 0068).
        $response = $this->profileRequest('/articles/1?relatedQuery[author][sort]=name');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['parameter' => 'relatedQuery[author]'],
            $this->firstError($this->decode($response))['source'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function aToOneRelationshipPathWithPageUnderTheProfileIs400(): void
    {
        // A [page] op addressed to a to-one path is a `400` (a single member has nothing
        // to page). Core's parser drops a [page] op silently, so the batcher detects it
        // off the raw relatedQuery family (ADR 0068).
        $response = $this->profileRequest('/articles/1?relatedQuery[author][page][number]=2');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['parameter' => 'relatedQuery[author]'],
            $this->firstError($this->decode($response))['source'] ?? null,
        );
    }

    // --- to-one nulling via relatedQuery[filter] on a primary request (ADR 0068) ---

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function relatedQueryFilterNullsAnExcludedToOneAndOmitsItFromIncluded(): void
    {
        // Article 1's author is Ada Lovelace (1). relatedQuery[author][filter][name]=Grace
        // Hopper excludes the single target, so the to-one linkage is nulled AND the
        // author is omitted from included[].
        $document = $this->profileDocument('/articles/1?include=author&relatedQuery[author][filter][name]=Grace%20Hopper');

        self::assertNull($this->toOneLinkage($document, 'author'));
        self::assertSame([], $this->includedIds($document, 'authors'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function relatedQueryFilterKeepsAMatchingToOneAndItsInclude(): void
    {
        // The matching filter keeps the to-one: filter[name]=Ada Lovelace matches author
        // 1, so the linkage and the include are unchanged.
        $document = $this->profileDocument('/articles/1?include=author&relatedQuery[author][filter][name]=Ada%20Lovelace');

        self::assertSame(['type' => 'authors', 'id' => '1'], $this->toOneLinkage($document, 'author'));
        self::assertSame(['1'], $this->includedIds($document, 'authors'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function relatedQueryFilterNullsAnExcludedToOnePerParentOnACollection(): void
    {
        // GET /articles?include=author with relatedQuery[author][filter][name]=Ada Lovelace:
        // each parent's to-one author is matched independently in ONE batched probe (no
        // N+1). Articles 1 and 3 are authored by Ada (kept); article 4 by Grace and
        // article 5 authorless (both nulled).
        $document = $this->profileDocument('/articles?include=author&relatedQuery[author][filter][name]=Ada%20Lovelace');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $expected = ['1' => ['type' => 'authors', 'id' => '1'], '3' => ['type' => 'authors', 'id' => '1'], '4' => null, '5' => null];
        $seen = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            if (!\array_key_exists($id, $expected)) {
                continue;
            }
            self::assertSame($expected[$id], $this->toOneLinkage($resource, 'author'), \sprintf('article "%s" author linkage', $id));
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected article was matched');

        // Only Ada's author resource survives the per-parent filter in included[].
        self::assertSame(['1'], $this->includedIds($document, 'authors'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anUnknownFilterKeyOnAToOnePathUnderTheProfileIs400(): void
    {
        // An unknown filter key on the to-one path is the to-many endpoint's same 400 —
        // the filter resolves against the merged vocabulary either way.
        $response = $this->profileRequest('/articles/1?relatedQuery[author][filter][nope]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[nope]'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function aNestedDottedRelationshipPathUnderTheProfileIs400(): void
    {
        // A dotted path addresses a relation of an INCLUDED resource; the batcher
        // windows only top-level relations, so an unhandled dotted path is rejected as
        // an unknown path rather than silently ignored (the family grammar parses, but
        // it is not a working address from this parent request).
        $response = $this->profileRequest('/articles/1?include=editors&relatedQuery[editors.articles][sort]=title');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['parameter' => 'relatedQuery[editors.articles]'],
            $this->firstError($this->decode($response))['source'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aCountableRelationshipObjectEmitsPlainFormPaginationLinksWithLast(): void
    {
        // editors is countable(); with `?withCount=editors` opting into the count (G21: a
        // windowed include is count-free until asked) its relationship object carries
        // first + last pagination links, in the PLAIN form against the relationship-linkage
        // endpoint, mirroring the relatedQuery sort — never the relatedQuery[…] form.
        $response = $this->handle(
            self::BASE_URI . '/articles/1?include=editors&withCount=editors&relatedQuery[editors][sort]=-name',
            extraServer: ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $links = $this->relationshipLinks($document['data'] ?? null, 'editors');

        $first = $this->href($links['first'] ?? null);
        self::assertStringContainsString('/articles/1/relationships/editors', $first);
        self::assertStringContainsString('sort=-name', $first, 'the link mirrors the relatedQuery sort in plain form');
        self::assertStringNotContainsString('relatedQuery', $first, 'the endpoint link never uses the profile form');
        self::assertStringNotContainsString('rQ%5B', $first);

        self::assertArrayHasKey('last', $links, 'the count opt-in emits a last link');
        self::assertNotNull($links['last'] ?? null);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function aNonCountableRelationshipObjectEmitsCountFreePaginationLinksWithoutLast(): void
    {
        // lazyComments is a distinct non-countable to-many: under the profile its
        // relationship object paginates count-free — a first link but no last (the
        // slice-1 count-free vs countable distinction, preserved).
        $document = $this->profileDocument('/articles/1?include=lazyComments');

        $links = $this->relationshipLinks($document['data'] ?? null, 'lazyComments');

        self::assertNotNull($links['first'] ?? null, 'a paginated relationship emits a first link');
        self::assertStringContainsString('/articles/1/relationships/lazyComments', $this->href($links['first']));
        self::assertNull($links['last'] ?? null, 'a non-countable relation emits no last link');
    }

    #[Test]
    #[Group('spec:profiles')]
    public function theResponseAdvertisesTheNegotiatedProfile(): void
    {
        $response = $this->profileRequest('/articles/1?include=editors&relatedQuery[editors][sort]=-name');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // jsonapi.profile carries the URI, and the Content-Type profile param echoes it.
        $document = $this->decode($response);
        $jsonapi = $document['jsonapi'] ?? null;
        self::assertIsArray($jsonapi);
        self::assertContains(RelationshipQueriesProfile::URI, (array) ($jsonapi['profile'] ?? []));

        $contentType = (string) $response->headers->get('Content-Type');
        self::assertStringContainsString('profile="' . RelationshipQueriesProfile::URI . '"', $contentType);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function theRelatedQueryFamilyIsRejectedWhenTheProfileIsNotNegotiated(): void
    {
        // The relatedQuery/rQ family is a profile keyword: it is recognized only when
        // the client negotiated the profile. Without negotiation it is an unrecognized
        // top-level family, so strict query-parameter validation (default on, bundle
        // ADR 0055) rejects it with a 400 keyed on the family base name — rather than
        // the old silent-ignore. The internal `[editors][sort]` path is irrelevant; the
        // family base `relatedQuery` is what is unrecognized.
        $response = $this->handle(self::BASE_URI . '/articles/1?include=editors&relatedQuery[editors][sort]=-name');
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'relatedQuery'], $error['source'] ?? null);
    }

    // --- rendered-data window gate (bundle ADR 0086) ---------------------------

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aRelatedQueryFilterOnANotIncludedLazyToManyDoesNotApply(): void
    {
        // `lazyComments` is a lazy (links-only-by-default) to-many backed by
        // `featuredComments` — article 1 features comments [1,2]. It is NOT included
        // and NOT `withData()`, so its linkage does not render data this request: the
        // window must NOT fire (bundle ADR 0086). A relatedQuery filter that would
        // narrow it to comment 1 alone has NO effect on the rendered relationship —
        // proving the filtered page was never windowed onto the parent.
        //
        // The wire is provider-shaped: on Doctrine the lazy default omits `data`
        // entirely (links-only — the load-state predicate stays "not loaded" because
        // no page was written onto the property), on the in-memory witness (no
        // load-state predicate) the FULL unfiltered set [1,2] renders. Either way the
        // filtered singleton [1] never leaks, and no window pagination link appears.
        $document = $this->profileDocument(
            '/articles/1?relatedQuery[lazyComments][filter][body]=' . \rawurlencode('First!'),
        );

        $relationshipObject = $this->relationshipObject($document['data'] ?? null, 'lazyComments');

        // The window never fired, so the relationship carries no relatedQuery-mirroring
        // pagination link (a windowed relation would emit a `first` carrying the filter).
        self::assertWindowDidNotFire($relationshipObject, 'body=First');

        // And the linkage is never the filtered singleton: either links-only (no data
        // member at all) or the full unfiltered membership — but NOT [1] alone.
        if (\array_key_exists('data', $relationshipObject)) {
            self::assertNotSame(
                ['1'],
                $this->linkageIds($document, 'lazyComments'),
                'a not-windowed lazy to-many must not render the filtered subset (no leak)',
            );
        }
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aRelatedQueryFilterWindowsAnIncludedLazyToMany(): void
    {
        // The same lazy `lazyComments`, now INCLUDED: its linkage data renders, so the
        // window fires and the relatedQuery filter applies — article 1's features [1,2]
        // narrow to comment 1 ("First!"), and the included resource reflects that page.
        // (Existing behaviour preserved: including a relation re-enables windowing.)
        $document = $this->profileDocument(
            '/articles/1?include=lazyComments&relatedQuery[lazyComments][filter][body]=' . \rawurlencode('First!'),
        );

        self::assertSame(['1'], $this->linkageIds($document, 'lazyComments'));
        self::assertSame(['1'], $this->includedIds($document, 'comments'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aRelatedQueryFilterWindowsAWithDataToManyEvenWhenNotIncluded(): void
    {
        // `comments` is a `withData()` to-many: its linkage data renders unconditionally
        // (`emitsDataOnlyWhenLoaded() === false`), so it is windowed even when NOT
        // included (bundle ADR 0086). Article 1 owns comments [1,2]; the relatedQuery
        // filter `commentBody` (a contains-match on the comment body) narrows the
        // rendered linkage to comment 1 ("First!"). The filter DOES apply because the
        // data renders — windowing a withData relation is correct, never a leak.
        $document = $this->profileDocument(
            '/articles/1?relatedQuery[comments][filter][commentBody]=First',
        );

        self::assertSame(['1'], $this->linkageIds($document, 'comments'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function windowingOneSharedColumnRelationDoesNotAlterASiblingBystandersLinkage(): void
    {
        // REGRESSION (bundle ADR 0086): a windowed relation must not corrupt a SIBLING
        // relation that shares its backing column. `comments` is a `withData()` to-many
        // (windowed) and `lockedComments` is a lazy to-many over the SAME `comments`
        // column (NEITHER addressed NOR included). Filtering `comments` to comment 1 must
        // narrow ONLY `comments` — `lockedComments`, the bystander, renders its OWN
        // membership untouched, because the windowed page is supplied to core out-of-band
        // (the relationship-linkage seam) rather than written onto the shared property.
        $document = $this->profileDocument(
            '/articles/1?relatedQuery[comments][filter][commentBody]=First',
        );

        // The window applied to `comments`: its linkage is the filtered singleton.
        self::assertSame(['1'], $this->linkageIds($document, 'comments'), 'the windowed relation is filtered');

        // The bystander is unaffected. The wire is provider-shaped: on Doctrine the lazy
        // default keeps `lockedComments` links-only (the column was never written, so the
        // load-state predicate stays "not loaded"); the in-memory witness (no predicate)
        // renders the FULL untouched membership [1,2]. Either way the filtered singleton
        // [1] NEVER leaks onto the bystander.
        $locked = $this->relationshipObject($document['data'] ?? null, 'lockedComments');
        if (\array_key_exists('data', $locked)) {
            self::assertSame(
                ['1', '2'],
                $this->linkageIds($document, 'lockedComments'),
                'a column-sharing bystander renders its own full membership, never the windowed sibling\'s filtered page',
            );
        }

        // And the bystander never inherits the windowed sibling's pagination link mirroring
        // the relatedQuery filter (it was not windowed at all).
        self::assertWindowDidNotFire($locked, 'commentBody=First');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function twoCoWindowedRelationsOverOneColumnEachRenderTheirOwnFilteredPage(): void
    {
        // CO-WINDOWED SHARED COLUMN (bundle ADR 0086): `comments` and `flaggedComments`
        // are BOTH `withData()` to-many relations over the SAME `comments` backing
        // column, so both render data this request and both are windowed. The
        // relationship-linkage seam is keyed per (parent, relation), so each carries
        // its OWN filtered page — proving two co-windowed relations sharing one column
        // do not cross-contaminate or leak into each other.
        //
        // Article 1 owns comments [1 "First!", 2 "Nice write-up."]. Each relation is
        // addressed by a DIFFERENT relatedQuery filter on its own scoped vocabulary:
        //  - `comments[filter][commentBody]=First` -> [1] (the `commentBody` scoped key);
        //  - `flaggedComments[filter][flaggedBody]=Nice` -> [2] (the `flaggedBody` key).
        $document = $this->profileDocument(
            '/articles/1'
                . '?relatedQuery[comments][filter][commentBody]=First'
                . '&relatedQuery[flaggedComments][filter][flaggedBody]=Nice',
        );

        // Each co-windowed relation renders ITS OWN filtered singleton — no leak across
        // the shared column, in either direction, on either provider.
        self::assertSame(['1'], $this->linkageIds($document, 'comments'), 'comments is filtered to "First!"');
        self::assertSame(['2'], $this->linkageIds($document, 'flaggedComments'), 'flaggedComments is filtered to "Nice write-up."');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    public function aFilteredWithCountOnANotIncludedToManyStillCountsTheFilteredSet(): void
    {
        // REGRESSION (bundle ADR 0086): gating the WINDOW must not regress the COUNT.
        // `editors` is a countable, lazy (links-only-by-default) many-to-many to
        // `authors`; article 1 has editors Ada Lovelace (1) and Grace Hopper (2). It is
        // NOT included, so the window does NOT fire — but a `?withCount` of its FILTERED
        // set must still be reported (the count path loads nothing and is independent of
        // the window). The `name` filter narrows the count to Grace Hopper alone: total 1.
        $response = $this->handle(
            self::BASE_URI . '/articles/1?withCount=editors'
                . '&relatedQuery[editors][filter][name]=' . \rawurlencode('Grace Hopper'),
            extraServer: ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="'
                . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $relationshipObject = $this->relationshipObject($document['data'] ?? null, 'editors');

        // The filtered count is reported via meta.total (count path unaffected by the
        // window gate) — one editor matches "Grace Hopper". The window did NOT fire, so
        // the linkage is not the filtered page (links-only on Doctrine, full set in
        // memory) — but the count is the FILTERED total regardless.
        $meta = $relationshipObject['meta'] ?? null;
        self::assertIsArray($meta, 'a counted relationship carries meta');
        self::assertSame(1, $meta['total'] ?? null, 'the filtered count is reported even though the relation is not windowed');

        self::assertWindowDidNotFire($relationshipObject, 'name=Grace');
    }

    // --- request helpers -------------------------------------------------------

    /**
     * A profile-negotiated GET: the `Accept` carries the Relationship Queries profile
     * URI in its `profile` media-type parameter, so the relatedQuery/rQ family parses.
     */
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
     * The linkage `data` ids of a resource's named relationship, in document order.
     * Accepts either a whole document (reads `data`) or a single resource object.
     *
     * @param array<string, mixed> $resourceOrDocument
     *
     * @return list<string>
     */
    private function linkageIds(array $resourceOrDocument, string $relationship): array
    {
        $resource = $resourceOrDocument['data'] ?? $resourceOrDocument;
        $relationships = $this->relationships($resource);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));

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
     * The linkage `data` of a resource's named TO-ONE relationship: a `{type, id}`
     * identifier, or `null` when the to-one was nulled (the filter excluded it). Accepts
     * either a whole document (reads `data`) or a single resource object.
     *
     * @param array<string, mixed> $resourceOrDocument
     *
     * @return array{type: mixed, id: mixed}|null
     */
    private function toOneLinkage(array $resourceOrDocument, string $relationship): ?array
    {
        $resource = $resourceOrDocument['data'] ?? $resourceOrDocument;
        $relationships = $this->relationships($resource);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));
        self::assertArrayHasKey('data', $relationshipObject, \sprintf('relationship "%s" carries linkage data', $relationship));

        $data = $relationshipObject['data'];
        if ($data === null) {
            return null;
        }

        self::assertIsArray($data);

        return ['type' => $data['type'] ?? null, 'id' => $data['id'] ?? null];
    }

    /**
     * The ids of the document's `included` resources of `$type`, in document order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedIds(array $document, string $type): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $ids = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) !== $type) {
                continue;
            }
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The `links` object of a resource's named relationship.
     *
     * @return array<string, mixed>
     */
    private function relationshipLinks(mixed $resource, string $relationship): array
    {
        $relationships = $this->relationships($resource);
        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));

        $links = $relationshipObject['links'] ?? [];
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        return $links;
    }

    /**
     * The whole relationship object of a resource's named relationship (links + any
     * data/meta), so a test can assert across its members.
     *
     * @return array<string, mixed>
     */
    private function relationshipObject(mixed $resource, string $relationship): array
    {
        $relationships = $this->relationships($resource);
        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));

        /** @var array<string, mixed> $relationshipObject */
        return $relationshipObject;
    }

    /**
     * Asserts a relationship object was NOT windowed by the profile (bundle ADR 0086):
     * a windowed relation emits a pagination `first`/`prev`/`next` link carrying the
     * relatedQuery sort/filter in plain form, so the absence of any link mentioning the
     * given filter/sort fragment witnesses that the window never fired.
     *
     * @param array<string, mixed> $relationshipObject
     */
    private static function assertWindowDidNotFire(array $relationshipObject, string $fragment): void
    {
        $links = $relationshipObject['links'] ?? [];
        self::assertIsArray($links);

        foreach (['first', 'prev', 'next', 'last'] as $rel) {
            $href = $links[$rel] ?? null;
            if ($href === null) {
                continue;
            }

            $value = \is_array($href) ? ($href['href'] ?? '') : $href;
            self::assertIsString($value);
            self::assertStringNotContainsString(
                $fragment,
                \rawurldecode($value),
                \sprintf('a not-windowed relation must not emit a "%s" link mirroring the relatedQuery', $rel),
            );
        }
    }

    /**
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
