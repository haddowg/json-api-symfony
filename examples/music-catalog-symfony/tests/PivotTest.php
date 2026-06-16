<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `belongsToMany` pivot witness (bundle ADR 0045, backs `relationships.md`):
 * `playlists.orderedTracks` is backed by the
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\PlaylistEntry}
 * association entity, which carries the `position`/`addedAt` pivot columns a plain
 * `#[ORM\ManyToMany]` join table cannot. The Doctrine adapter auto-detects that
 * entity (the only to-many on {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist}
 * whose target also has a `ManyToOne` to {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track}),
 * runs ONE DQL statement over it, and renders the pivot values as each member's
 * `meta.pivot` while recognising them as `?filter`/`?sort` keys on that relation's
 * related endpoint.
 *
 * The Morning Mix playlist orders three tracks through pivot rows: Exit Music
 * (track 3) at position 1, Airbag (track 1) at position 2, Paranoid Android
 * (track 2, explicit) at position 3. The `tracks` resource's `explicit` default
 * filter hides the explicit member from the related collection, leaving the
 * visible order [3, 1] by ascending position.
 *
 * The `position` field is declared WRITABLE (a plain `Integer::make('position')`,
 * no `->readOnly()`) so it can be set / reordered through the linkage `meta`, while
 * `addedAt` is `readOnly()` (server-owned, stamped by the entity's `#[ORM\PrePersist]`).
 * The write witnesses below add a member with a position, PATCH-reorder the whole
 * playlist and read the new positions back, reject an out-of-range position with a
 * `422`, and prove the server-owned `addedAt` is set on a freshly-created row even
 * though the wire `meta` cannot write it. Mutating the playlist's relationship goes
 * through the owner gate (`securityUpdate: is_granted('EDIT', object)`), so the
 * writes authenticate as the seeded owner.
 *
 * Boundaries proved here (and documented in the README): pivot is **Doctrine-only**,
 * scoped to this one related endpoint — a pivot key 400s on the primary `/tracks`
 * collection and on the plain `tracks` relation, no pivot meta renders there, and a
 * pivot-meta write is ignored on the in-memory provider (it has no association
 * entity); on Doctrine the writable `position` upserts while the readOnly `addedAt`
 * never does.
 */
#[Group('spec:fetching-relationships')]
final class PivotTest extends MusicCatalogKernelTestCase
{
    private const string PLAYLIST_ID = '00000000-0000-4000-8000-000000000001';

    /** The seeded owner of Morning Mix — the EDIT-gate subject for a pivot write. */
    private const array OWNER = ['PHP_AUTH_USER' => 'ada@example.com', 'PHP_AUTH_PW' => 'pass'];

    #[Test]
    public function aPivotRelatedEndpointRendersPerMemberPivotMeta(): void
    {
        // sort=position visible order: Exit Music (3) @ pos 1, Airbag (1) @ pos 2 —
        // track 2 is explicit and hidden by the related type's default filter.
        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=position');

        self::assertSame(['3', '1'], $this->ids($document));

        // Each member carries its own pivot values as meta.pivot, typed per the
        // declared fields (position an int, addedAt an ISO-8601 string).
        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['3'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertSame('2024-04-01T09:00:00+00:00', $this->pivotField($byId['3'] ?? [], 'addedAt'));
        self::assertSame('2024-04-02T09:00:00+00:00', $this->pivotField($byId['1'] ?? [], 'addedAt'));
    }

    #[Test]
    public function aPivotRelationshipEndpointRendersPerMemberLinkageMeta(): void
    {
        // The relationship-linkage endpoint renders ALL members off the parent (the
        // explicit filter does not apply to raw linkage), each identifier carrying
        // meta.pivot — no attributes (linkage only).
        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/relationships/orderedTracks');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $byIdPosition = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertArrayNotHasKey('attributes', $identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $byIdPosition[$id] = $this->pivotField($identifier, 'position');
        }

        \ksort($byIdPosition);

        // track 1 (Airbag) @ 2, track 2 (Paranoid Android) @ 3, track 3 (Exit Music) @ 1.
        self::assertSame(['1' => 2, '2' => 3, '3' => 1], $byIdPosition);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortOrdersTheRelatedCollectionAndFlips(): void
    {
        $ascending = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=position');
        self::assertSame(['3', '1'], $this->ids($ascending));

        // The order flips under -position: the same membership, reversed.
        $descending = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=-position');
        self::assertSame(['1', '3'], $this->ids($descending));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterNarrowsTheRelatedCollection(): void
    {
        // filter[position]=2 keeps only the member at pivot position 2 (Airbag, id 1).
        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?filter[position]=2');

        self::assertSame(['1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterComposesWithARelatedFilterInOnePage(): void
    {
        // The pivot sort composes with the related `tracks` `like` filter[title] in
        // ONE query. "music" matches Exit Music (track 3) only; a page of size two
        // holds the single match without a short page.
        $document = $this->fetch(
            '/playlists/' . self::PLAYLIST_ID . '/orderedTracks?filter[title]=music&sort=position&page[size]=2&page[number]=1',
        );

        self::assertSame(['3'], $this->ids($document));

        $page = $this->pageMeta($document);
        self::assertSame(1, $page['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotKeyIsUnrecognisedOnThePrimaryCollection(): void
    {
        // `position` is a pivot key scoped to the related endpoint only; on the
        // primary /tracks collection it is undeclared → 400 (Doctrine-only, scoped).
        self::assertSame(400, $this->handle('/tracks?filter[position]=1')->getStatusCode());
        self::assertSame(400, $this->handle('/tracks?sort=position')->getStatusCode());
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotKeyIsUnrecognisedOnThePlainRelation(): void
    {
        // The plain `tracks` join relation carries no pivot data, so a pivot key 400s
        // there too — pivot is scoped to the `orderedTracks` association-entity
        // relation alone.
        self::assertSame(400, $this->handle('/playlists/' . self::PLAYLIST_ID . '/tracks?sort=position')->getStatusCode());
    }

    #[Test]
    public function aPlainRelationRendersNoPivotMeta(): void
    {
        // The plain `tracks` relation renders members with no meta.pivot — pivot meta
        // is exclusive to the association-entity relation.
        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/tracks');

        foreach ($this->byId($document) as $member) {
            self::assertNull($this->pivotField($member, 'position'));
        }
    }

    // --- writing / reordering pivot data -------------------------------------

    #[Test]
    #[Group('spec:updating-relationships')]
    public function addingATrackWithAPositionCreatesTheRowWithThatPivotValue(): void
    {
        // POST a member to the relationship endpoint carrying the writable `position`
        // in its linkage meta. Mysterons (track 4) is on no playlist yet — adding it
        // at position 4 creates a PlaylistEntry row with that pivot value. The write
        // goes through the owner gate.
        $response = $this->handle(
            '/playlists/' . self::PLAYLIST_ID . '/relationships/orderedTracks',
            'POST',
            ['data' => [['type' => 'tracks', 'id' => '4', 'meta' => ['position' => 4]]]],
            self::OWNER,
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // Read the related collection back: Mysterons is now a member at pivot
        // position 4, the server-owned addedAt stamped on the new row.
        $byId = $this->byId($this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=position&page[size]=10'));
        self::assertSame(4, $this->pivotField($byId['4'] ?? [], 'position'));
        self::assertSame('2025-01-01T00:00:00+00:00', $this->pivotField($byId['4'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aFullReplacementReordersThePlaylistInPlace(): void
    {
        // PATCH a full replacement to reorder: Airbag (1) → position 1, Exit Music (3)
        // → position 2 (swapping their seeded 2/1), and DROP Paranoid Android (2). The
        // existing rows are updated IN PLACE (their server addedAt survives), the
        // dropped member's row is removed.
        $response = $this->handle(
            '/playlists/' . self::PLAYLIST_ID . '/relationships/orderedTracks',
            'PATCH',
            ['data' => [
                ['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 1]],
                ['type' => 'tracks', 'id' => '3', 'meta' => ['position' => 2]],
            ]],
            self::OWNER,
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // Read back the new order: Airbag (1) @ 1 then Exit Music (3) @ 2; Paranoid
        // Android (2) is gone (it was dropped by the replacement, not just hidden).
        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=position');
        self::assertSame(['1', '3'], $this->ids($document));

        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['3'] ?? [], 'position'));
        // Airbag's row was updated in place: its seeded addedAt is preserved, not
        // re-stamped by PrePersist (which only fires on a freshly-created row).
        self::assertSame('2024-04-02T09:00:00+00:00', $this->pivotField($byId['1'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function anOutOfRangePivotValueIs422AtTheMetaPointerWithNoWrite(): void
    {
        // position 0 violates the writable field's min(1): a 422 pointed at the linkage
        // meta, and NO write (the membership and positions are unchanged afterwards).
        $response = $this->handle(
            '/playlists/' . self::PLAYLIST_ID . '/relationships/orderedTracks',
            'PATCH',
            ['data' => [['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 0]]]],
            self::OWNER,
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/0/meta/position', $this->firstErrorPointer($response));

        // The store is unchanged: the seeded order [3, 1] (Exit Music @ 1, Airbag @ 2)
        // still stands, Airbag still at position 2.
        $byId = $this->byId($this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=position'));
        self::assertSame(2, $this->pivotField($byId['1'] ?? [], 'position'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReadOnlyAddedAtSuppliedInMetaIsIgnored(): void
    {
        // addedAt is a readOnly pivot field: a value supplied in meta is never written.
        // Add Mysterons (4) supplying both position (applied) and addedAt (ignored) —
        // the new row takes the server PrePersist default, not the supplied date.
        $response = $this->handle(
            '/playlists/' . self::PLAYLIST_ID . '/relationships/orderedTracks',
            'POST',
            ['data' => [[
                'type' => 'tracks',
                'id' => '4',
                'meta' => ['position' => 4, 'addedAt' => '1999-12-31T00:00:00+00:00'],
            ]]],
            self::OWNER,
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $byId = $this->byId($this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=position&page[size]=10'));
        self::assertSame(4, $this->pivotField($byId['4'] ?? [], 'position'));
        // The supplied addedAt was ignored; the server-owned default stands.
        self::assertSame('2025-01-01T00:00:00+00:00', $this->pivotField($byId['4'] ?? [], 'addedAt'));
        self::assertNotSame('1999-12-31T00:00:00+00:00', $this->pivotField($byId['4'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function theSameMetaConventionAppliesInlineInAWholeResourceWrite(): void
    {
        // The SAME linkage meta inline in a whole-resource PATCH of the playlist:
        // reorder orderedTracks to Airbag (1) @ 1, Exit Music (3) @ 2, dropping
        // Paranoid Android. Goes through the same owner gate and persister seam.
        $response = $this->handle(
            '/playlists/' . self::PLAYLIST_ID,
            'PATCH',
            ['data' => [
                'type' => 'playlists',
                'id' => self::PLAYLIST_ID,
                'relationships' => [
                    'orderedTracks' => ['data' => [
                        ['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 1]],
                        ['type' => 'tracks', 'id' => '3', 'meta' => ['position' => 2]],
                    ]],
                ],
            ]],
            self::OWNER,
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->fetch('/playlists/' . self::PLAYLIST_ID . '/orderedTracks?sort=position');
        self::assertSame(['1', '3'], $this->ids($document));

        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['3'] ?? [], 'position'));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);

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
     * The `source.pointer` of the first error in an error document, or null — the
     * 422 witness for an invalid pivot value.
     */
    private function firstErrorPointer(\Symfony\Component\HttpFoundation\Response $response): ?string
    {
        $errors = $this->decode($response)['errors'] ?? null;
        if (!\is_array($errors) || $errors === []) {
            return null;
        }

        $first = $errors[0] ?? null;
        if (!\is_array($first)) {
            return null;
        }

        $source = $first['source'] ?? null;
        if (!\is_array($source)) {
            return null;
        }

        $pointer = $source['pointer'] ?? null;

        return \is_string($pointer) ? $pointer : null;
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
}
