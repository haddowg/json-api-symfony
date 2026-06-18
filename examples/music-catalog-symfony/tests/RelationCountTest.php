<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The countable-relations witness (bundle ADR 0052, core ADR 0057; backs the
 * `relationships.md` "Counting a to-many relation" note). A to-many relation
 * declared `countable()` exposes its cardinality two ways, both keyed `total`:
 *
 *  - `?withCount=rel` on a primary read activates `meta.total` on that
 *    RELATIONSHIP OBJECT — proven here on a single album and, BATCHED per parent,
 *    across the whole `/albums` collection (one grouped pushed-down `COUNT` per
 *    requested relation, never N — observably per-parent because each album holds
 *    a different number of tracks);
 *  - the related-collection endpoint (`GET /albums/{id}/tracks`) emits the
 *    pagination `meta.page.total` and a `last` link, AS the
 *    {@see RelatedCollectionTest} already asserts.
 *
 * The example marks `albums.tracks`, `playlists.tracks` and `tracks.playlists`
 * countable; `artists.albums` is deliberately LEFT non-countable, so its related
 * endpoint is the count-free witness: NO `meta.page.total`, NO `last` link, while
 * `self`/`first` still render (a `next` would signal "there is a next page"
 * without a count). A `?withCount` naming a non-countable to-many
 * (`orderedTracks`), a to-one (`artist`) or an unknown name is rejected up front by
 * the primary serializer's countable set — a `400` with `source.parameter`
 * `withCount`.
 *
 * Album 1 (OK Computer) seeds three tracks, album 2 (Dummy) one — so a batched
 * collection count is observably per-parent, not a single repeated value.
 */
#[Group('spec:fetching-relationships')]
final class RelationCountTest extends MusicCatalogKernelTestCase
{
    // `?withCount` is gated behind the Relationship Counts profile; every count read
    // here negotiates it.
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    #[Test]
    public function withCountEmitsTheRelationshipObjectTotalOnASingleAlbum(): void
    {
        // ?withCount=tracks activates meta.total on the album's tracks relationship
        // object — the count is pushed down (never materialising the collection).
        // Album 1 holds three tracks, but the `tracks` resource's explicit=false
        // default filter hides the explicit one (Paranoid Android), so the count is
        // 2 — the SAME filtered set the related endpoint pages, not raw membership
        // (bundle ADR 0060). It matches GET /albums/1/tracks (see RelatedCollectionTest).
        $document = $this->fetch('/albums/1?withCount=tracks');

        self::assertSame(2, $this->relationshipTotal($document['data'] ?? null, 'tracks'));
    }

    #[Test]
    public function withCountBatchesTheRelationshipObjectTotalAcrossTheAlbumsCollection(): void
    {
        // The whole /albums collection with ?withCount=tracks: each album's OWN
        // tracks total, counted in ONE grouped query across the page — proving the
        // batch is per-parent, not a single repeated value. The count honours the
        // `tracks` explicit=false default filter, so album 1 reports 2 (its explicit
        // track is hidden, as on the endpoint) and album 2 reports 1 (bundle ADR 0060).
        $document = $this->fetch('/albums?withCount=tracks');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $expected = ['1' => 2, '2' => 1];
        $seen = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            if (!\is_string($id) || !isset($expected[$id])) {
                continue;
            }

            self::assertSame($expected[$id], $this->relationshipTotal($resource, 'tracks'), \sprintf('album "%s" tracks total', $id));
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'every expected album was counted');
    }

    #[Test]
    public function aRelationNotNamedInWithCountCarriesNoTotal(): void
    {
        // `tracks` is countable() but only `tracks` is named here; a relationship the
        // request did not name carries no meta.total — the relationship-object total
        // is gated by ?withCount, not by countable() alone. `artist` is the cleanest
        // unnamed relation (it has no other meta), so its absence of meta is exact.
        $document = $this->fetch('/albums/1?withCount=tracks');

        $relationships = $this->relationships($document['data'] ?? null);
        $artist = $relationships['artist'] ?? null;
        self::assertIsArray($artist);
        self::assertArrayNotHasKey('meta', $artist, 'an unnamed relation carries no total');
    }

    #[Test]
    public function aCountableRelatedEndpointPaginatesCountFreeByDefault(): void
    {
        // Since G21 the related-collection endpoint is count-free BY DEFAULT even for a
        // COUNTABLE relation: a bare fetch windows without a COUNT, so there is no
        // meta.page.total. (perPage is 2, album 1 has two non-explicit visible tracks,
        // so it is one full page with no further page — hence no `next`/`last`.) The
        // total returns only under `?withCount=_self_` on the countable relation, or
        // with withCount() on the relation's paginator.
        $document = $this->fetch('/albums/1/tracks');

        $page = $this->pageMeta($document);
        self::assertArrayNotHasKey('total', $page, 'count-free by default: no page total');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayNotHasKey('last', $links, 'count-free by default: no last link');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aNonCountableRelatedEndpointPaginatesCountFree(): void
    {
        // `artists.albums` is NOT countable(), so its related endpoint paginates
        // COUNT-FREE: no COUNT query runs, so there is no meta.page.total and no
        // `last` link — only self/first (a `next` would signal a further page). This
        // is the gap-G21 partial-pagination mechanism for relationships.
        $document = $this->fetch('/artists/1/albums');

        $page = $this->pageMeta($document);
        self::assertArrayNotHasKey('total', $page, 'a count-free page omits the total');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('self', $links);
        self::assertArrayHasKey('first', $links);
        self::assertArrayNotHasKey('last', $links, 'a count-free page omits the last link');
    }

    #[Test]
    #[Group('spec:errors')]
    public function aNonCountableToManyInWithCountIs400(): void
    {
        // `orderedTracks` is a to-many but NOT countable(): core rejects it up front
        // against the album/playlist's countable set (source.parameter withCount).
        $response = $this->handle('/playlists/00000000-0000-4000-8000-000000000001?withCount=orderedTracks', extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstErrorSource($response));
    }

    #[Test]
    #[Group('spec:errors')]
    public function aToOneRelationInWithCountIs400(): void
    {
        // `artist` is a to-one — counting is a to-many concern, so it is never in the
        // countable set and ?withCount=artist is a 400.
        $response = $this->handle('/albums/1?withCount=artist', extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstErrorSource($response));
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownRelationInWithCountIs400(): void
    {
        $response = $this->handle('/albums/1?withCount=nope', extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstErrorSource($response));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path, extraServer: ['HTTP_ACCEPT' => self::COUNTS_ACCEPT]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The `meta.total` of a resource's named relationship object, asserted to be an
     * int (a missing total fails loudly rather than returning null).
     */
    private function relationshipTotal(mixed $resource, string $name): int
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
     * @return array<string, mixed>
     */
    private function firstErrorSource(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $error = $errors[0];
        self::assertIsArray($error);

        $source = $error['source'] ?? null;
        self::assertIsArray($source);

        /** @var array<string, mixed> $source */
        return $source;
    }
}
