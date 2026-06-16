<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The WRITABLE `belongsToMany` pivot suite (Doctrine only): the `playlists.tracks`
 * relation declares a WRITABLE `position` (an `Integer` pivot field with `min(1)`)
 * and a server-owned readOnly `addedAt` (a `DateTime`), both on the auto-detected
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity}
 * association entity (bundle ADR 0046).
 *
 * It proves the association-entity diff: adding a member with pivot meta creates the
 * join row with the value; a full replacement REORDERS the existing rows in place
 * (dropping removed members, creating added ones) and reads back the new positions;
 * a pivot value violating a constraint is a `422` pointed at the linkage meta with NO
 * write; a readOnly `addedAt` supplied in meta is ignored (server-set); a required
 * writable pivot field absent on a freshly-created row is a `422`; and the SAME
 * write convention runs inline in a whole-resource write.
 */
final class DoctrinePivotWriteTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function addingAMemberWithPivotMetaCreatesTheAssociationRowWithTheValue(): void
    {
        // Playlist 2 starts with just Intro@1. Add Bridge (id 3) at position 5 via the
        // relationship endpoint — a new association row is created with that position.
        $response = $this->handle(
            self::BASE_URI . '/playlists/2/relationships/tracks',
            'POST',
            ['data' => [['type' => 'tracks', 'id' => '3', 'meta' => ['position' => 5]]]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // Read the related collection back: Bridge now a member at pivot position 5.
        $document = $this->fetchDocument('/playlists/2/tracks?sort=position');
        $byId = $this->byId($document);
        self::assertArrayHasKey('3', $byId);
        self::assertSame(5, $this->pivotField($byId['3'] ?? [], 'position'));
        // The pre-existing Intro row is untouched.
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aFullReplacementReordersExistingRowsAndSyncsMembership(): void
    {
        // Playlist 1 has Intro@1, Outro@2, Bridge@3. Replace with: Intro@3, Bridge@1
        // (existing rows updated IN PLACE), and DROP Outro (not in the incoming set).
        $response = $this->handle(
            self::BASE_URI . '/playlists/1/relationships/tracks',
            'PATCH',
            ['data' => [
                ['type' => 'tracks', 'id' => '3', 'meta' => ['position' => 1]],
                ['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 3]],
            ]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->fetchDocument('/playlists/1/tracks?sort=position');

        // Membership is exactly the incoming set (Outro dropped), ordered by the new
        // positions: Bridge(3)@1 then Intro(1)@3.
        self::assertSame(['3', '1'], $this->ids($document));

        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['3'] ?? [], 'position'));
        self::assertSame(3, $this->pivotField($byId['1'] ?? [], 'position'));
        self::assertArrayNotHasKey('2', $byId, 'Outro should have been removed');
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReorderUpdatesTheExistingRowInPlaceNotByDeleteAndRecreate(): void
    {
        // Move Intro from position 1 to position 9 via add (upsert) — the existing
        // row is updated in place, so the server-set addedAt is preserved (not reset
        // to a new PrePersist timestamp).
        $before = $this->byId($this->fetchDocument('/playlists/1/tracks?sort=position'));
        $introAddedAt = $this->pivotField($before['1'] ?? [], 'addedAt');
        self::assertSame('2024-01-01T00:00:00+00:00', $introAddedAt);

        $response = $this->handle(
            self::BASE_URI . '/playlists/1/relationships/tracks',
            'POST',
            ['data' => [['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 9]]]],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $after = $this->byId($this->fetchDocument('/playlists/1/tracks?sort=position'));
        self::assertSame(9, $this->pivotField($after['1'] ?? [], 'position'));
        // The original addedAt survives — the row was updated, not recreated.
        self::assertSame('2024-01-01T00:00:00+00:00', $this->pivotField($after['1'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPivotValueViolatingAConstraintIs422WithNoWrite(): void
    {
        // position 0 violates min(1): a 422 pointed at the linkage meta, and no write
        // (the membership and positions are unchanged afterwards).
        $response = $this->handle(
            self::BASE_URI . '/playlists/1/relationships/tracks',
            'PATCH',
            ['data' => [['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 0]]]],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/0/meta/position', $this->firstErrorPointer($response));

        // The store is unchanged: playlist 1 still has its three members at 1, 2, 3.
        $document = $this->fetchDocument('/playlists/1/tracks?sort=position');
        self::assertSame(['1', '2', '3'], $this->ids($document));
        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReadOnlyPivotFieldSuppliedInMetaIsIgnored(): void
    {
        // addedAt is a readOnly pivot field: supplying it in meta is ignored (never
        // written — the row keeps its server-set value), while the writable position
        // is applied. A new Bridge row on playlist 2 takes the server addedAt default,
        // not the supplied one.
        $response = $this->handle(
            self::BASE_URI . '/playlists/2/relationships/tracks',
            'POST',
            ['data' => [[
                'type' => 'tracks',
                'id' => '3',
                'meta' => ['position' => 4, 'addedAt' => '1999-12-31T00:00:00+00:00'],
            ]]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $byId = $this->byId($this->fetchDocument('/playlists/2/tracks?sort=position'));
        self::assertSame(4, $this->pivotField($byId['3'] ?? [], 'position'));
        // The supplied addedAt was ignored; the server PrePersist default stands.
        self::assertSame('2025-01-01T00:00:00+00:00', $this->pivotField($byId['3'] ?? [], 'addedAt'));
        self::assertNotSame('1999-12-31T00:00:00+00:00', $this->pivotField($byId['3'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aRequiredWritablePivotFieldAbsentOnANewRowIs422(): void
    {
        // A whole-resource CREATE (create context) of a playlist whose inline tracks
        // linkage omits the required `position` for a new row → 422 at the linkage meta.
        $response = $this->handle(
            self::BASE_URI . '/playlists',
            'POST',
            ['data' => [
                'type' => 'playlists',
                'attributes' => ['name' => 'Fresh'],
                'relationships' => [
                    'tracks' => ['data' => [['type' => 'tracks', 'id' => '1']]],
                ],
            ]],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/relationships/tracks/data/0/meta/position', $this->firstErrorPointer($response));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aRequiredWritablePivotFieldAbsentOnANewRelationshipEndpointRowIs422(): void
    {
        // A relationship-endpoint POST (Mode::Add) adding Bridge (id 3) to playlist 2
        // with NO meta CREATES a new association row, so the required `position` must be
        // present: a 422 at the linkage meta, before persist (never a DB NOT-NULL 500).
        $response = $this->handle(
            self::BASE_URI . '/playlists/2/relationships/tracks',
            'POST',
            ['data' => [['type' => 'tracks', 'id' => '3']]],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/0/meta/position', $this->firstErrorPointer($response));

        // No write: playlist 2 still has only its pre-existing Intro member.
        self::assertSame(['1'], $this->ids($this->fetchDocument('/playlists/2/tracks?sort=position')));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aRequiredWritablePivotFieldAbsentOnAWholeResourcePatchNewRowIs422(): void
    {
        // A whole-resource PATCH replacing playlist 2's tracks with a NEW member (Bridge,
        // id 3) carrying no meta CREATES a new association row — so the required
        // `position` must be present even though the resource operation is an update:
        // a 422 at the linkage meta, before persist (never a DB NOT-NULL 500).
        $response = $this->handle(
            self::BASE_URI . '/playlists/2',
            'PATCH',
            ['data' => [
                'type' => 'playlists',
                'id' => '2',
                'relationships' => [
                    'tracks' => ['data' => [
                        ['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 1]],
                        ['type' => 'tracks', 'id' => '3'],
                    ]],
                ],
            ]],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/relationships/tracks/data/1/meta/position', $this->firstErrorPointer($response));

        // No write: playlist 2 still has only its pre-existing Intro member at position 1.
        $document = $this->fetchDocument('/playlists/2/tracks?sort=position');
        self::assertSame(['1'], $this->ids($document));
        $byId = $this->byId($document);
        self::assertSame(1, $this->pivotField($byId['1'] ?? [], 'position'));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aWholeResourceWriteAppliesPivotMetaInline(): void
    {
        // The SAME meta write convention inline in a whole-resource create: a new
        // playlist with two tracks at explicit positions. The 201 resource is created
        // and the related collection reads back the pivot positions.
        $response = $this->handle(
            self::BASE_URI . '/playlists',
            'POST',
            ['data' => [
                'type' => 'playlists',
                'attributes' => ['name' => 'Inline'],
                'relationships' => [
                    'tracks' => ['data' => [
                        ['type' => 'tracks', 'id' => '2', 'meta' => ['position' => 1]],
                        ['type' => 'tracks', 'id' => '1', 'meta' => ['position' => 2]],
                    ]],
                ],
            ]],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $created = $this->decode($response);
        $id = $this->createdId($created);

        $byId = $this->byId($this->fetchDocument('/playlists/' . $id . '/tracks?sort=position'));
        self::assertSame(1, $this->pivotField($byId['2'] ?? [], 'position'));
        self::assertSame(2, $this->pivotField($byId['1'] ?? [], 'position'));
        // The server-owned addedAt is set on each new row.
        self::assertSame('2025-01-01T00:00:00+00:00', $this->pivotField($byId['2'] ?? [], 'addedAt'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function removingAMemberDropsItsAssociationRow(): void
    {
        // DELETE carries no pivot — it removes the incoming members' rows. Drop Outro
        // (id 2) from playlist 1; Intro and Bridge remain.
        $response = $this->handle(
            self::BASE_URI . '/playlists/1/relationships/tracks',
            'DELETE',
            ['data' => [['type' => 'tracks', 'id' => '2']]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->fetchDocument('/playlists/1/tracks?sort=position');
        self::assertSame(['1', '3'], $this->ids($document));
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    private function firstErrorPointer(\Symfony\Component\HttpFoundation\Response $response): ?string
    {
        $document = $this->decode($response);
        $errors = $document['errors'] ?? null;
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
     */
    private function createdId(array $document): string
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
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
