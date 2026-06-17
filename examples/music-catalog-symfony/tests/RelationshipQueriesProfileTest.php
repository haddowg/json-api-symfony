<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Relationship Queries profile witness (bundle ADR 0053, core ADR 0058; backs
 * the README "Filtering and sorting a relationship from the primary request"
 * section and `docs/relationships.md`). A client filters and sorts a *relationship's*
 * linkage from the PRIMARY request — addressing the relationship by its include path
 * — but only after **negotiating** the profile in the `Accept` header's `profile`
 * media-type parameter; without it the `relatedQuery` / `rQ` family is ignored and
 * the profile is not advertised (today's behaviour).
 *
 * `AlbumResource`'s `tracks` is the countable witness: its related-collection
 * vocabulary (the `tracks` resource's own `title`/`trackNumber` sorts and
 * `like`/`explicit` filters, MERGED with the relation's own scoped `duration` sort
 * and `longerThan` filter, bundle ADR 0044) is now reachable from a primary
 * `GET /albums/{id}?include=tracks` request, and — because it is `countable()` — its
 * relationship object emits a `last` pagination link. Album 1 seeds tracks 1 (Airbag,
 * 284s), 2 (Paranoid Android, 383s, explicit) and 3 (Exit Music, 264s); the related
 * `tracks` resource's default `explicit=false` filter hides track 2, so the windowed
 * page-1 linkage is tracks 1 and 3.
 *
 * `users.playlists` is the count-free witness (it is to-many, includable, and
 * deliberately NOT `countable()`): under the profile its relationship object
 * paginates count-free — a `first` link but no `last`, preserving the Slice 1
 * count-free vs countable distinction. (`playlists.orderedTracks` is left to its own
 * endpoint: a pivot/derived relation has no plain Doctrine association for the
 * per-parent window to scope, so it is not addressed from a parent request.)
 */
#[Group('spec:profiles')]
final class RelationshipQueriesProfileTest extends MusicCatalogKernelTestCase
{
    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function relatedQuerySortOrdersAnIncludedRelationshipsLinkageAndIncluded(): void
    {
        // sort=-duration orders the album's tracks linkage descending by length:
        // Airbag (284s) precedes Exit Music (264s); Paranoid Android (383s) is
        // explicit and hidden by the related tracks default filter. The linkage data
        // AND the included resources reflect that page-1 order.
        $document = $this->profileDocument('/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration');

        self::assertSame(['1', '3'], $this->linkageIds($document, 'tracks'));
        self::assertSame(['1', '3'], $this->includedIds($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function relatedQuerySortAcceptsTheRelatedResourcesOwnSortKey(): void
    {
        // The vocabulary is the related-collection endpoint's: a `tracks` resource
        // key (title) works alongside the relation's own scoped `duration`. sort=title
        // is byte-asc: "Airbag" (1) precedes "Exit Music…" (3).
        $document = $this->profileDocument('/albums/1?include=tracks&relatedQuery[tracks][sort]=title');

        self::assertSame(['1', '3'], $this->linkageIds($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function relatedQueryFilterNarrowsAnIncludedRelationshipsLinkage(): void
    {
        // filter[longerThan] is the relation's own scoped filter on length_seconds;
        // addressed through tracks it narrows the album's linkage to the one track
        // over 280s (Airbag, 284s).
        $document = $this->profileDocument('/albums/1?include=tracks&relatedQuery[tracks][filter][longerThan]=280');

        self::assertSame(['1'], $this->linkageIds($document, 'tracks'));
        self::assertSame(['1'], $this->includedIds($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function relatedQueryFilterUsesTheRelatedResourceVocabulary(): void
    {
        // filter[explicit] is the `tracks` resource's own filter: explicit=true
        // overrides its default and surfaces the otherwise-hidden explicit track 2 —
        // proving the filter scopes against the RELATED (tracks) vocabulary.
        $document = $this->profileDocument('/albums/1?include=tracks&relatedQuery[tracks][filter][explicit]=true');

        self::assertSame(['2'], $this->linkageIds($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function relatedQuerySortAppliesPerParentOnACollectionInclude(): void
    {
        // GET /albums?include=tracks with a per-relationship sort: each album's tracks
        // are windowed to page 1 ordered by -duration INDEPENDENTLY. Album 1 -> [1,3]
        // (284s, 264s); album 2 -> [4] (Mysterons). The visible explicit track is
        // hidden per parent.
        $document = $this->profileDocument('/albums?include=tracks&relatedQuery[tracks][sort]=-duration');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $expected = ['1' => ['1', '3'], '2' => ['4']];
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
                $this->linkageIds($resource, 'tracks'),
                \sprintf('album "%s" tracks windowed to page 1 ordered -duration per parent', $id),
            );
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected album was windowed');
    }

    #[Test]
    public function theRqShorthandIsIdenticalToTheCanonicalFamily(): void
    {
        $canonical = $this->profileDocument('/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration');
        $shorthand = $this->profileDocument('/albums/1?include=tracks&rQ[tracks][sort]=-duration');

        self::assertSame(
            $this->linkageIds($canonical, 'tracks'),
            $this->linkageIds($shorthand, 'tracks'),
            'rQ yields the same linkage order as relatedQuery',
        );
        self::assertSame(['1', '3'], $this->linkageIds($shorthand, 'tracks'));
    }

    #[Test]
    public function onAConflictTheCanonicalRelatedQueryWinsOverTheRqShorthand(): void
    {
        // Both families target tracks[sort] with opposite directions; the canonical
        // relatedQuery (-duration -> [1,3]) wins over rQ (duration asc -> [3,1]).
        $document = $this->profileDocument(
            '/albums/1?include=tracks&rQ[tracks][sort]=duration&relatedQuery[tracks][sort]=-duration',
        );

        self::assertSame(['1', '3'], $this->linkageIds($document, 'tracks'), 'canonical relatedQuery wins the conflict');
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownFilterKeyUnderTheProfileIs400(): void
    {
        // The unknown key is the same 400 the related-collection endpoint gives, with
        // the PLAIN-form key in source.parameter (filter[nope], not relatedQuery[…]).
        $response = $this->profileRequest('/albums/1?relatedQuery[tracks][filter][nope]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[nope]'], $this->firstError($response)['source'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownSortKeyUnderTheProfileIs400(): void
    {
        $response = $this->profileRequest('/albums/1?relatedQuery[tracks][sort]=nope');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownRelationshipPathUnderTheProfileIs400(): void
    {
        // No `bogusRel` relation on albums: the path resolves to nothing, so it is the
        // related-collection endpoint's same 400, with the canonical profile param in
        // source.parameter — never a silent 200 masking a client typo.
        $response = $this->profileRequest('/albums/1?relatedQuery[bogusRel][sort]=title');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'relatedQuery[bogusRel]'], $this->firstError($response)['source'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function aToOneRelationshipPathUnderTheProfileIs400(): void
    {
        // `artist` is a to-one (BelongsTo): addressing it with a list-op sort is the
        // locked spec rule's 400 (a to-one path for a list op).
        $response = $this->profileRequest('/albums/1?relatedQuery[artist][sort]=name');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'relatedQuery[artist]'], $this->firstError($response)['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRelatedQueryFilterOnAToOneNullsTheLinkageWhenItExcludesTheTarget(): void
    {
        // null-a-to-one-when-a-relation-filter-excludes-its-target (bundle ADR 0068):
        // on a PRIMARY request, `relatedQuery[artist][filter][name]` resolves the
        // album's `artist` relation-scoped `filter[name]` against the single target.
        // A `[filter]` op is the ONE relaxation for a to-one (a `[sort]`/`[page]` is a
        // 400, asserted above): a match keeps the linkage and the `included` artist; a
        // mismatch nulls the linkage AND drops the target from `included[]`.
        $matched = $this->profileDocument(
            '/albums/1?include=artist&relatedQuery[artist][filter][name]=Radiohead',
        );
        self::assertSame(['type' => 'artists', 'id' => '1'], $this->toOneLinkage($matched, 'artist'));
        self::assertSame(['1'], $this->includedIds($matched, 'artists'));

        $excluded = $this->profileDocument(
            '/albums/1?include=artist&relatedQuery[artist][filter][name]=Portishead',
        );
        self::assertNull($this->toOneLinkage($excluded, 'artist'), 'the excluded to-one linkage is null');
        self::assertSame([], $this->includedIds($excluded, 'artists'), 'the excluded target drops from included[]');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountableRelationshipObjectEmitsPlainFormPaginationLinksWithLast(): void
    {
        // tracks is countable(): its relationship object carries first + last
        // pagination links, in the PLAIN form against the relationship-linkage
        // endpoint, mirroring the relatedQuery sort — never the relatedQuery[…] form.
        $document = $this->profileDocument('/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration');

        $links = $this->relationshipLinks($document['data'] ?? null, 'tracks');

        $first = $this->href($links['first'] ?? null);
        self::assertStringContainsString('/albums/1/relationships/tracks', $first);
        self::assertStringContainsString('sort=-duration', $first, 'the link mirrors the relatedQuery sort in plain form');
        self::assertStringNotContainsString('relatedQuery', $first, 'the endpoint link never uses the profile form');
        self::assertStringNotContainsString('rQ%5B', $first);

        self::assertArrayHasKey('last', $links, 'a countable relation emits a last link');
        self::assertNotNull($links['last'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aNonCountableRelationshipObjectEmitsCountFreePaginationLinksWithoutLast(): void
    {
        // users.playlists is a to-many that is NOT countable(): under the profile its
        // relationship object paginates count-free — a first link but no last (the
        // Slice 1 count-free vs countable distinction, preserved). The users type is
        // admin-only, so this rides the named `admin` server.
        $document = $this->adminProfileDocument('/admin/users/1?include=playlists');

        $links = $this->relationshipLinks($document['data'] ?? null, 'playlists');

        self::assertNotNull($links['first'] ?? null, 'a paginated relationship emits a first link');
        self::assertStringContainsString('/users/1/relationships/playlists', $this->href($links['first']));
        self::assertStringNotContainsString('relatedQuery', $this->href($links['first']));
        self::assertArrayNotHasKey('last', $links, 'a non-countable relation emits no last link');
    }

    #[Test]
    public function theResponseAdvertisesTheNegotiatedProfile(): void
    {
        $response = $this->profileRequest('/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration');
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
    public function theRelatedQueryFamilyIsRejectedWhenTheProfileIsNotNegotiated(): void
    {
        // The relatedQuery/rQ family is a profile keyword recognized only when the
        // client negotiated the profile. Without negotiation it is an unrecognized
        // top-level query-parameter family, so strict query-parameter validation
        // (json_api.strict_query_parameters, default on) rejects it with a 400 keyed
        // on the family base name — rather than the old silent-ignore.
        $response = $this->handle('/albums/1?include=tracks&relatedQuery[tracks][sort]=-duration');
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'relatedQuery'], $error['source'] ?? null);
    }

    // --- request helpers -------------------------------------------------------

    /**
     * A profile-negotiated GET: the `Accept` carries the Relationship Queries profile
     * URI in its `profile` media-type parameter, so the relatedQuery/rQ family parses.
     */
    private function profileRequest(string $path): Response
    {
        return $this->handle($path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileDocument(string $path): array
    {
        $response = $this->profileRequest($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The admin-server twin of {@see profileDocument()}: the `users` type is exposed
     * only on the named `admin` server, which the seeded `ada@example.com` reaches via
     * a stateless bearer token.
     *
     * @return array<string, mixed>
     */
    private function adminProfileDocument(string $path): array
    {
        $response = $this->handle($path, extraServer: [
            'HTTP_ACCEPT' => self::PROFILE_ACCEPT,
            'HTTP_AUTHORIZATION' => 'Bearer ada@example.com',
        ]);

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
     * The linkage of a TO-ONE relationship — the single `{type,id}` identifier, or
     * `null` when the to-one is empty/excluded. Accepts a whole document or a resource.
     *
     * @param array<string, mixed> $resourceOrDocument
     *
     * @return array<string, mixed>|null
     */
    private function toOneLinkage(array $resourceOrDocument, string $relationship): ?array
    {
        $resource = $resourceOrDocument['data'] ?? $resourceOrDocument;
        $relationships = $this->relationships($resource);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));
        self::assertArrayHasKey('data', $relationshipObject, \sprintf('relationship "%s" carries linkage', $relationship));

        $data = $relationshipObject['data'];
        if ($data === null) {
            return null;
        }

        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
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
     * @return array<string, mixed>
     */
    private function firstError(Response $response): array
    {
        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $error = $errors[0];
        self::assertIsArray($error);

        /** @var array<string, mixed> $error */
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
