<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `belongsToMany` pivot acceptance suite (Doctrine only): the `playlists.tracks`
 * relation is backed by the {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity}
 * association entity (auto-detected), which carries the `position`/`addedAt` pivot
 * columns. It proves pivot values render as `meta.pivot` on BOTH the related
 * endpoint and the relationship-linkage endpoint, that `?sort`/`?filter` by a pivot
 * field order/narrow the collection, that a pivot filter composes with a related
 * filter in one correctly-paginated page, and the documented boundaries (a pivot
 * key is unrecognised on the primary collection; the in-memory provider has no
 * pivot at all — asserted in {@see InMemoryPivotBoundaryTest}).
 */
final class DoctrinePivotRelatedCollectionTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedEndpointRendersPerMemberPivotMeta(): void
    {
        $document = $this->fetchDocument('/playlists/1/tracks?sort=position');

        self::assertSame(['1', '2', '3'], $this->ids($document));

        // Each member carries its own pivot values as meta.pivot, typed per the
        // declared fields (position is an int, addedAt an ISO-8601 string).
        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['2'] ?? [], 'position'));
        self::assertSame(3, $this->pivotField($byId['3'] ?? [], 'position'));
        self::assertSame('2024-01-01T00:00:00+00:00', $this->pivotField($byId['1'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountOnAPivotRelationCountsDistinctMembers(): void
    {
        // The pivot relation is countable(): ?withCount=tracks emits meta.total on
        // the tracks relationship object, counting DISTINCT far members. Playlist 1
        // has three distinct tracks (Intro@1, Outro@2, Bridge@3), so the total is 3.
        $document = $this->fetchDocument('/playlists/1?withCount=tracks');

        self::assertSame(3, $this->relationshipTotal($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountOnAPivotRelationMatchesTheEndpointTotalUnderDuplicateMembership(): void
    {
        // Playlist 3 carries duplicate membership (Intro at two positions + Outro):
        // three association rows over TWO distinct tracks. The ?withCount total counts
        // DISTINCT far members (2), so it agrees with the related-collection endpoint's
        // page total (also 2, see aPivotRelatedCollectionDedupesDuplicateMembership)
        // and the deduped rendered linkage — one consistent `total` semantic (ADR 0052).
        $document = $this->fetchDocument('/playlists/3?withCount=tracks');

        self::assertSame(2, $this->relationshipTotal($document, 'tracks'));

        // The endpoint reports the SAME total for the same relation/parent.
        $endpoint = $this->fetchDocument('/playlists/3/tracks?sort=position');
        $meta = $endpoint['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertSame(2, $page['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRequestScopedCountHolderIsAResettableSharedService(): void
    {
        // The request-scoped count holder is a singleton, so a ?withCount read's counts
        // would otherwise survive into a later write/linkage render in a long-lived
        // container (a worker reusing the kernel). It is wired as a SHARED service that
        // implements ResetInterface, so framework autoconfiguration tags it kernel.reset
        // and the container clears it between messages. This asserts the wiring (a single
        // shared instance, resettable); the set/reset contract itself is covered by
        // RequestScopedRelationshipCountTest.
        $container = static::getContainer();

        $holder = $container->get(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount::class);
        self::assertInstanceOf(\Symfony\Contracts\Service\ResetInterface::class, $holder);

        // The same shared instance is handed back each time — a per-request reset of the
        // one holder is what clears a prior request's counts (a non-shared holder would
        // never see the leak in the first place, but the count seam needs the one Server
        // to render through it).
        self::assertSame($holder, $container->get(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount::class));
    }

    /**
     * The `meta.total` of the primary resource's named relationship object.
     *
     * @param array<string, mixed> $document
     */
    private function relationshipTotal(array $document, string $name): int
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship);

        $meta = $relationship['meta'] ?? null;
        self::assertIsArray($meta);

        $total = $meta['total'] ?? null;
        self::assertIsInt($total);

        return $total;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelationshipEndpointRendersPerMemberLinkageMeta(): void
    {
        $document = $this->fetchDocument('/playlists/1/relationships/tracks');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        // The linkage identifiers each carry meta.pivot for their member, riding
        // core's identifier-meta render path (no attributes — linkage only). Pair the
        // id with its pivot position in document order.
        $byIdPosition = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertArrayNotHasKey('attributes', $identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $byIdPosition[] = [$id, $this->pivotField($identifier, 'position')];
        }

        \usort($byIdPosition, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        self::assertSame([['1', 1], ['2', 2], ['3', 3]], $byIdPosition);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortOrdersTheRelatedCollectionAndFlips(): void
    {
        $ascending = $this->fetchDocument('/playlists/1/tracks?sort=position');
        self::assertSame(['1', '2', '3'], $this->ids($ascending));

        // The order flips under -position: the same membership, reversed.
        $descending = $this->fetchDocument('/playlists/1/tracks?sort=-position');
        self::assertSame(['3', '2', '1'], $this->ids($descending));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortPrecedesARelatedSortInRequestOrder(): void
    {
        // `?sort=position,title` orders by the PIVOT key first: position ascends
        // Intro(1), Outro(2), Bridge(3).
        $pivotFirst = $this->fetchDocument('/playlists/1/tracks?sort=position,title');
        self::assertSame(['1', '2', '3'], $this->ids($pivotFirst));

        // `?sort=title,position` orders by the RELATED key first: title ascends
        // Bridge(3), Intro(1), Outro(2) — a different order. Before the fix BOTH
        // requests returned this list, because the shared applier appended every
        // related sort before any pivot sort, silently demoting a pivot-first sort.
        $relatedFirst = $this->fetchDocument('/playlists/1/tracks?sort=title,position');
        self::assertSame(['3', '1', '2'], $this->ids($relatedFirst));

        // The two orders genuinely differ — the request directive order is honoured
        // across both aliases, not flattened to related-then-pivot.
        self::assertNotSame($this->ids($pivotFirst), $this->ids($relatedFirst));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterNarrowsTheRelatedCollection(): void
    {
        // filter[position]=2 keeps only the member at pivot position 2 (Outro, id 2).
        $document = $this->fetchDocument('/playlists/1/tracks?filter[position]=2');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterComposesWithARelatedFilterInOnePage(): void
    {
        // filter[title] (the related `tracks` vocabulary, contains "o" → Intro,
        // Outro, NOT Bridge) composes with the pivot sort in ONE query; a page of
        // size 2 holds both, so the page is full (no short page).
        $document = $this->fetchDocument('/playlists/1/tracks?filter[title]=o&sort=position&page[size]=2&page[number]=1');

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('page', $meta);
        $page = $meta['page'];
        self::assertIsArray($page);
        // Two matched the composed filter, page size two — the total is exactly two,
        // so the page is full and there is no second page (no short page).
        self::assertSame(2, $page['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedCollectionDedupesDuplicateMembership(): void
    {
        // Playlist 3 has Intro at two positions and Outro at one — three association
        // rows, two distinct far members. The page must group to one row per member,
        // so the total is two (not three) and the collection holds two distinct ids.
        $document = $this->fetchDocument('/playlists/3/tracks?sort=position');

        self::assertSame(['1', '2'], $this->ids($document));

        // page[size]=1 must hand back ONE distinct member per page and never repeat a
        // member across pages — the duplicate rows must not split a member's window.
        $first = $this->fetchDocument('/playlists/3/tracks?sort=position&page[size]=1&page[number]=1');
        $second = $this->fetchDocument('/playlists/3/tracks?sort=position&page[size]=1&page[number]=2');

        $firstIds = $this->ids($first);
        $secondIds = $this->ids($second);
        self::assertCount(1, $firstIds);
        self::assertCount(1, $secondIds);
        self::assertNotSame($firstIds, $secondIds, 'a member was duplicated across pages');
        self::assertSame(['1', '2'], [...$firstIds, ...$secondIds]);

        // The reported total is the distinct member count (two), so a client paging
        // by it never requests a phantom third page.
        $meta = $first['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertSame(2, $page['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aNonCountablePivotRelatedEndpointPaginatesCountFree(): void
    {
        // `orderedTracks` is the SAME association entity as `tracks` but left
        // NON-countable, so its related endpoint paginates count-free (bundle ADR
        // 0052): page one of two over playlist 1's three distinct members holds two
        // items, signals a further page through `next`, and emits NO total and NO
        // `last` — the universal countable() gate reaches the pivot path.
        $document = $this->fetchDocument('/playlists/1/orderedTracks?sort=position&page[size]=2&page[number]=1');

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertArrayNotHasKey('total', $page, 'a non-countable pivot endpoint must not COUNT');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('next', $links, 'a further page is signalled by `next`');
        self::assertArrayNotHasKey('last', $links, 'a count-free page has no `last` link');

        // Each member still carries its pivot meta — the count-free path renders the
        // same pivot values, only without the page total.
        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['2'] ?? [], 'position'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedCollectionScopesToItsParent(): void
    {
        // Playlist 2 shares Intro (id 1) only — per-parent scoping must not bleed
        // playlist 1's rows in.
        $document = $this->fetchDocument('/playlists/2/tracks?sort=position');

        self::assertSame(['1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotKeyIsUnrecognisedOnThePrimaryCollection(): void
    {
        // `position` is a pivot key, scoped to the related endpoint only; on the
        // primary /tracks collection it is undeclared → 400.
        $response = $this->handle(self::BASE_URI . '/tracks?filter[position]=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortKeyIsUnrecognisedOnThePrimaryCollection(): void
    {
        $response = $this->handle(self::BASE_URI . '/tracks?sort=position');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
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
     * The named pivot value under a resource/identifier's `meta.pivot`, or null when
     * absent — a typed extraction so the assertions stay PHPStan-clean.
     *
     * @param array<string, mixed> $resource
     */
    private function pivotField(array $resource, string $field): mixed
    {
        $meta = $resource['meta'] ?? null;
        if (!\is_array($meta)) {
            return null;
        }

        $pivot = $meta['pivot'] ?? null;
        if (!\is_array($pivot)) {
            return null;
        }

        return $pivot[$field] ?? null;
    }

    /**
     * The primary data resources keyed by id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $byId = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            /** @var array<string, mixed> $resource */
            $byId[$id] = $resource;
        }

        return $byId;
    }
}
