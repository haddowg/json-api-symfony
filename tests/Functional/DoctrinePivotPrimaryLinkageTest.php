<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The PRIMARY-document pivot-linkage acceptance suite (Doctrine only, bundle ADR
 * 0102): a `belongsToMany` pivot relation's per-member pivot values render as identifier
 * `meta.pivot` in a PRIMARY-resource document's relationships block — wherever that
 * relation's linkage data renders — exactly as they already do on the related
 * (`GET /playlists/1/tracks`) and relationship-linkage (`GET
 * /playlists/1/relationships/tracks`) endpoints.
 *
 * It proves the gap closed three ways: a compound `?include` on a single resource, a
 * compound `?include` on a collection, and a relation that renders linkage data BY
 * DEFAULT (`withData()`) with NO `?include` at all. The included resource objects
 * stay untouched (the pivot rides the LINKAGE identifier, never the resource).
 */
final class DoctrinePivotPrimaryLinkageTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aCompoundIncludeRendersPivotMetaOnThePrimaryDocumentLinkage(): void
    {
        // GET /playlists/1?include=orderedTracks — the orderedTracks linkage in the
        // primary resource's relationships block carries each member's meta.pivot,
        // riding core's identifier-meta render path (the same path the related and
        // relationship endpoints use). Before the fix the linkage carried NO pivot.
        $document = $this->fetchDocument('/playlists/1?include=orderedTracks');

        $linkage = $this->relationshipLinkage($document, 'orderedTracks');

        // Pair each linkage member's id with its pivot position (seeded 1/2/3 for
        // Intro/Outro/Bridge on playlist 1).
        $byIdPosition = [];
        foreach ($linkage as $identifier) {
            self::assertIsArray($identifier);
            // The pivot rides the linkage identifier — a bare type/id/meta object,
            // never a full resource (no attributes on a linkage identifier).
            self::assertArrayNotHasKey('attributes', $identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $byIdPosition[] = [$id, $this->pivotField($identifier, 'position')];
        }

        \usort($byIdPosition, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        self::assertSame([['1', 1], ['2', 2], ['3', 3]], $byIdPosition);

        // The typed addedAt rides too — proving the field cast threads through the
        // batched per-parent map exactly as the single-parent path.
        $byId = $this->linkageById($linkage);
        self::assertSame('2024-01-01T00:00:00+00:00', $this->pivotField($byId['1'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theIncludedResourceObjectsAlsoCarryPivotViaGetMeta(): void
    {
        // Pivot rides core's `getMeta()` path, which the transformer renders into BOTH
        // a resource identifier AND a full resource (the related endpoint already
        // renders pivot on the full far resource the same way). Core builds a compound
        // document's `included` expansion through the SAME relationship-bound serializer
        // it builds the linkage identifier with, so a member expanded into `included`
        // carries the same `meta.pivot` as its linkage identifier — consistent with the
        // existing pivot model (bundle ADR 0102). This is asserted (not merely
        // tolerated) so the behaviour is intentional and pinned.
        $document = $this->fetchDocument('/playlists/1?include=orderedTracks');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertNotSame([], $included, 'the tracks are expanded into included');

        $positionsById = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            // An included member is a FULL resource (it carries attributes).
            self::assertArrayHasKey('attributes', $resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $positionsById[$id] = $this->pivotField($resource, 'position');
        }

        \ksort($positionsById);
        self::assertSame(['1' => 1, '2' => 2, '3' => 3], $positionsById);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aCompoundIncludeOnACollectionRendersPivotPerParent(): void
    {
        // GET /playlists?include=orderedTracks — EACH parent's orderedTracks linkage in
        // the collection document carries its OWN members' pivot, fetched in ONE batched
        // per-parent pivot-map read (no N+1). Playlist 1 has Intro@1/Outro@2/Bridge@3;
        // playlist 2 shares Intro@1 only; playlist 3 carries duplicate membership
        // (Intro + Outro, deduped to one representative pivot row per member).
        $document = $this->fetchDocument('/playlists?include=orderedTracks');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $pivotByParent = [];
        foreach ($data as $playlist) {
            self::assertIsArray($playlist);
            $id = $playlist['id'] ?? null;
            self::assertIsString($id);

            $relationships = $playlist['relationships'] ?? null;
            self::assertIsArray($relationships);
            $relationship = $relationships['orderedTracks'] ?? null;
            self::assertIsArray($relationship);
            $linkage = $relationship['data'] ?? null;
            self::assertIsArray($linkage);

            $members = [];
            foreach ($linkage as $identifier) {
                self::assertIsArray($identifier);
                $memberId = $identifier['id'] ?? null;
                self::assertIsString($memberId);
                // A member that renders pivot carries a non-null position; a member with
                // no pivot map entry would carry null. Record whether each member's pivot
                // rendered (true) so scoping is provable without pinning a representative
                // position for the deduped duplicate-membership parent.
                $members[$memberId] = $this->pivotField($identifier, 'position') !== null;
            }
            \ksort($members);
            $pivotByParent[$id] = $members;
        }

        \ksort($pivotByParent);

        // Per-parent scoping holds: each parent's linkage carries only its own members,
        // each with its own pivot. Playlist 1: Intro/Outro/Bridge (ids 1/2/3); playlist
        // 2: shared Intro (id 1) only; playlist 3: two distinct members (Intro id 1,
        // Outro id 2) — duplicate membership deduped to one representative pivot row each.
        self::assertSame(
            [
                '1' => ['1' => true, '2' => true, '3' => true],
                '2' => ['1' => true],
                '3' => ['1' => true, '2' => true],
            ],
            $pivotByParent,
        );

        // The actual seeded positions ride the linkage for the unambiguous parents
        // (playlist 1 has no duplicate membership): Intro@1, Outro@2, Bridge@3.
        $playlist1 = $this->relationshipLinkage(
            $this->fetchDocument('/playlists/1?include=orderedTracks'),
            'orderedTracks',
        );
        $positions = [];
        foreach ($playlist1 as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $positions[$id] = $this->pivotField($identifier, 'position');
        }
        \ksort($positions);
        self::assertSame(['1' => 1, '2' => 2, '3' => 3], $positions);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aDefaultRenderedLinkageCarriesPivotWithoutInclude(): void
    {
        // `dataTracks` renders its linkage data BY DEFAULT (withData()), so a plain
        // GET /playlists/1 — with NO ?include — carries each member's meta.pivot on the
        // dataTracks linkage. This proves pivot renders wherever the linkage data renders,
        // not just under ?include.
        $document = $this->fetchDocument('/playlists/1');

        $linkage = $this->relationshipLinkage($document, 'dataTracks');

        $byIdPosition = [];
        foreach ($linkage as $identifier) {
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
    #[Group('spec:fetching-relationships')]
    public function aLazyNotIncludedPivotRelationCarriesNoPivotEvenIfItsLinkageRenders(): void
    {
        // The rendered-data gate (bundle ADR 0102, mirroring the include-windowing gate
        // of bundle ADR 0086) keys off `included OR !emitsDataOnlyWhenLoaded()` — NOT a
        // post-hoc check of whether the linkage happened to render. `tracks` is a LAZY
        // pivot relation (emitsDataOnlyWhenLoaded) that is NOT included on a plain
        // GET /playlists/1, so it is gated OUT of the pivot wrap (no batched pivot-map
        // read, no rebind) even though this fixture's `extractUsing` makes its linkage
        // data render. So its linkage carries NO meta.pivot — a deliberate, declared
        // boundary: opt a pivot relation into primary-document pivot with `withData()`
        // (or `?include`), exactly as the include-windowing path requires.
        $document = $this->fetchDocument('/playlists/1');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $tracks = $relationships['tracks'] ?? null;
        self::assertIsArray($tracks);

        $linkage = $tracks['data'] ?? null;
        self::assertIsArray($linkage);
        self::assertNotSame([], $linkage);

        foreach ($linkage as $identifier) {
            self::assertIsArray($identifier);
            $meta = $identifier['meta'] ?? [];
            self::assertIsArray($meta);
            self::assertArrayNotHasKey('pivot', $meta, 'a lazy, not-included pivot relation is gated out of the pivot wrap');
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aMultiTypeBackedParentWithAnEncoderCarriesPivotOnThePrimaryLinkage(): void
    {
        // `encoded-playlists` is a SECOND type over the SAME PlaylistEntity as
        // `playlists`, carrying a custom id encoder (wire id `pl-…`) and registered
        // AFTER `playlists` (so an entity-class reverse-lookup would resolve the
        // first-registered no-encoder `playlists`). Its `dataTracks` pivot relation
        // renders linkage data by default, so GET /encoded-playlists/pl-1 must carry
        // each member's meta.pivot — proving the batched per-parent map keys its outer
        // entry by the SERVED type's encoder (matching the serializer's getId()), not a
        // reverse-resolved one (which would key by the bare int and silently drop the
        // pivot, the BLOCKER this fix closes).
        $document = $this->fetchDocument('/encoded-playlists/pl-1');

        // The primary resource's own id is the ENCODED wire id, confirming the served
        // type's encoder is in play (so the outer map key must match it).
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('pl-1', $data['id'] ?? null);

        $linkage = $this->relationshipLinkage($document, 'dataTracks');

        $byIdPosition = [];
        foreach ($linkage as $identifier) {
            self::assertIsArray($identifier);
            self::assertArrayNotHasKey('attributes', $identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $byIdPosition[] = [$id, $this->pivotField($identifier, 'position')];
        }

        \usort($byIdPosition, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        // Playlist 1 has Intro@1/Outro@2/Bridge@3 (track ids 1/2/3); the pivot rides
        // each linkage identifier even though the served type is not the first
        // registered for PlaylistEntity.
        self::assertSame([['1', 1], ['2', 2], ['3', 3]], $byIdPosition);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aConditionallyHiddenPivotRelationIsNotResurrectedOntoThePrimaryDocument(): void
    {
        // `hiddenDataTracks` is a pivot relation that renders its linkage data
        // (withData()) but is HIDDEN for this request (`hidden(fn …)` → always true).
        // Core's getRelationships() excludes a per-request hidden relation, so the
        // primary document must omit it entirely. The pivot decorator must honour that
        // exclusion — NOT re-add the relation (and its pivot meta) the author hid for
        // this request (bundle ADR 0102). Before the guard the decorator iterated EVERY
        // selected pivot relation and re-added the omitted key, leaking it.
        $document = $this->fetchDocument('/playlists/1');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        self::assertArrayNotHasKey(
            'hiddenDataTracks',
            $relationships,
            'a per-request hidden pivot relation must not be resurrected onto the relationships block',
        );

        // The VISIBLE default-rendered pivot relation still carries its pivot — the
        // guard only suppresses relations the inner serializer already omitted.
        self::assertArrayHasKey('dataTracks', $relationships);
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
     * The linkage (`data`) array of the primary resource's named relationship object.
     *
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    private function relationshipLinkage(array $document, string $name): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship);

        $linkage = $relationship['data'] ?? null;
        self::assertIsArray($linkage);

        return \array_values($linkage);
    }

    /**
     * The linkage identifiers keyed by their id.
     *
     * @param list<mixed> $linkage
     *
     * @return array<string, array<string, mixed>>
     */
    private function linkageById(array $linkage): array
    {
        $byId = [];
        foreach ($linkage as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            /** @var array<string, mixed> $identifier */
            $byId[$id] = $identifier;
        }

        return $byId;
    }

    /**
     * The named pivot value under an identifier's `meta.pivot`, or null when absent.
     *
     * @param array<string, mixed> $identifier
     */
    private function pivotField(array $identifier, string $field): mixed
    {
        $meta = $identifier['meta'] ?? null;
        if (!\is_array($meta)) {
            return null;
        }

        $pivot = $meta['pivot'] ?? null;
        if (!\is_array($pivot)) {
            return null;
        }

        return $pivot[$field] ?? null;
    }
}
