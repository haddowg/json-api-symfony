<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Favorite;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Library;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\PlaylistEntry;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User;

/**
 * Plain, deterministic fixtures (no Foundry) — the Doctrine twin of core's
 * in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Seed.php Seed}.
 * {@see into()} persists a coherent object graph for all seven entity-backed
 * types through the {@see EntityManagerInterface}: artists own albums, albums own
 * tracks, tracks belong to playlists (the plain join table), a playlist orders its
 * tracks through {@see PlaylistEntry} pivot rows carrying `position`/`addedAt`, a
 * user owns playlists + a library, and the favorites/library carry their
 * polymorphic targets.
 *
 * Counts mirror core so the same values back the query/relationship tests: album
 * "1" has three tracks (so a per-relation perPage=2 paginator yields two pages),
 * the slug/title/genre values back the filter tests, and the album date pair backs
 * the directional CompareField. Album "3" is unpublished so the
 * `PublishedAlbumsExtension` scope (built next phase) has a row to hide.
 *
 * The entity-backed rows use **store-provided ids** (the example's norm): the
 * auto-increment integer PKs are assigned by the database on flush, in persist
 * order — so the rows persisted first take ids `1, 2, 3, …` per table, exactly the
 * ids the tests assert (and the same values core's hand-set seed used). Playlist
 * alone keeps a hand-set UUID (its app-generated string PK).
 *
 * The graph is built in passes: construct every row, wire the references, then
 * persist + flush once. The polymorphic targets are stored as
 * `targetType`/`targetId` pairs ({@see Favorite}) — the `targetId` is the favoritable
 * member's *wire* id (the stringified auto-increment integer) — and the library's
 * items are left to the custom provider to resolve, so only the pointers persist here.
 */
final class Seed
{
    public static function into(EntityManagerInterface $entityManager): void
    {
        // --- Artists (ids 1, 2 assigned by the DB in persist order) -----------
        $radiohead = new Artist(
            name: 'Radiohead',
            slug: 'radiohead',
            website: 'https://radiohead.com',
            bio: 'An English rock band formed in Abingdon.',
            trackCount: 3,
            createdAt: new \DateTimeImmutable('2001-05-01T09:00:00+00:00'),
        );
        $portishead = new Artist(
            name: 'Portishead',
            slug: 'portishead',
            website: null,
            bio: null,
            trackCount: 1,
            createdAt: new \DateTimeImmutable('2002-08-15T12:30:00+00:00'),
        );

        // --- Albums (ids 1, 2, 3; album "3" is unpublished — the scope hides it) --
        $okComputer = new Album(
            title: 'OK Computer',
            averageRating: 9.8,
            releasedAt: new \DateTimeImmutable('1997-05-21T00:00:00+00:00'),
            published: true,
            explicit: false,
            availableFrom: new \DateTimeImmutable('1997-05-21'),
            availableUntil: new \DateTimeImmutable('2030-12-31'),
            releaseInfo: ['label' => 'Parlophone', 'catalogueNumber' => 'NODATA 01'],
            artist: $radiohead,
        );
        $dummy = new Album(
            title: 'Dummy',
            averageRating: 9.1,
            releasedAt: new \DateTimeImmutable('1994-08-22T00:00:00+00:00'),
            published: true,
            explicit: false,
            availableFrom: new \DateTimeImmutable('1994-08-22'),
            availableUntil: new \DateTimeImmutable('2031-01-01'),
            releaseInfo: ['label' => 'Go! Beat', 'catalogueNumber' => 'NODATA 02'],
            artist: $portishead,
        );
        $unreleased = new Album(
            title: 'Unreleased Sessions',
            averageRating: null,
            releasedAt: null,
            published: false,
            explicit: false,
            // The backed-enum `status` witness: an unreleased album is `upcoming`
            // (the two published albums keep the constructor default `released`).
            status: 'upcoming',
            availableFrom: null,
            availableUntil: null,
            releaseInfo: null,
            artist: $radiohead,
        );

        // --- Tracks (ids 1, 2, 3, 4 in persist order) -------------------------
        $airbag = new Track(
            title: 'Airbag',
            trackNumber: 1,
            length_seconds: 284,
            explicit: false,
            genres: ['rock', 'alternative'],
            previewOffset: '00:00:30',
            album: $okComputer,
        );
        $paranoidAndroid = new Track(
            title: 'Paranoid Android',
            trackNumber: 2,
            length_seconds: 383,
            explicit: true,
            genres: ['rock', 'progressive'],
            previewOffset: '00:01:00',
            album: $okComputer,
        );
        $exitMusic = new Track(
            title: 'Exit Music (For a Film)',
            trackNumber: 3,
            length_seconds: 264,
            explicit: false,
            genres: ['rock'],
            previewOffset: null,
            album: $okComputer,
        );
        $mysterons = new Track(
            title: 'Mysterons',
            trackNumber: 1,
            length_seconds: 305,
            explicit: false,
            genres: ['trip-hop'],
            previewOffset: '00:00:45',
            album: $dummy,
        );

        // --- Library + User (ids 1, 1; the OneToOne owning FK is on the user) --
        $library = new Library();

        $ada = new User(
            email: 'ada@example.com',
            displayName: 'Ada',
            birthDate: new \DateTimeImmutable('1990-12-10'),
            preferences: ['theme' => 'dark', 'autoplay' => true],
            lastSeenIp: '203.0.113.7',
            password: null,
            library: $library,
        );

        // --- Playlist (app-keyed UUID id, hand-set in the fixture) ------------
        $morningMix = new Playlist(
            id: '00000000-0000-4000-8000-000000000001',
            title: 'Morning Mix',
            slug: 'morning-mix',
            public: true,
            externalId: '11111111-1111-4111-8111-111111111111',
            owner: $ada,
        );
        // track ↔ playlists (the owning side is on Track) — the PLAIN join table,
        // carrying no pivot columns.
        $airbag->playlists->add($morningMix);
        $paranoidAndroid->playlists->add($morningMix);

        // --- Playlist entries (the PIVOT relation `playlists.orderedTracks`) -------
        // Real pivot values on the PlaylistEntry association entity: `position`
        // orders the tracklist (Exit Music@1, Airbag@2, Paranoid Android@3 — inserted
        // out of position order so an order assertion cannot pass on insertion order),
        // `weight` is a second writable field stored well above each position (so the
        // `weight >= position` cross-pivot rule holds for the seeded rows and a partial
        // update can compare a new weight against the merged stored position), and
        // `addedAt` records when each was added. Track 2 (Paranoid Android) is
        // explicit, so the `tracks` resource's default filter hides it from the
        // related collection — leaving Exit Music(3)@1 then Airbag(1)@2.
        $entryAirbag = new PlaylistEntry(
            playlist: $morningMix,
            track: $airbag,
            position: 2,
            weight: 100,
            addedAt: new \DateTimeImmutable('2024-04-02T09:00:00+00:00'),
        );
        $entryExitMusic = new PlaylistEntry(
            playlist: $morningMix,
            track: $exitMusic,
            position: 1,
            weight: 100,
            addedAt: new \DateTimeImmutable('2024-04-01T09:00:00+00:00'),
        );
        $entryParanoidAndroid = new PlaylistEntry(
            playlist: $morningMix,
            track: $paranoidAndroid,
            position: 3,
            weight: 100,
            addedAt: new \DateTimeImmutable('2024-04-03T09:00:00+00:00'),
        );

        // --- Favorites (ids 1, 2, 3; MorphTo stored as a targetType/targetId pair;
        //     the targetId is the favoritable member's wire id) -----------------
        $favTrack = new Favorite(
            favoritedAt: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            targetType: 'tracks',
            targetId: '2',
            user: $ada,
        );
        $favAlbum = new Favorite(
            favoritedAt: new \DateTimeImmutable('2024-02-20T14:30:00+00:00'),
            targetType: 'albums',
            targetId: '1',
            user: $ada,
        );
        $favArtist = new Favorite(
            favoritedAt: new \DateTimeImmutable('2024-03-05T08:15:00+00:00'),
            targetType: 'artists',
            targetId: '2',
            user: $ada,
        );

        // --- Persist (order so FK-bearing rows follow their targets) ----------
        foreach ([$library, $radiohead, $portishead] as $row) {
            $entityManager->persist($row);
        }
        foreach ([$okComputer, $dummy, $unreleased] as $row) {
            $entityManager->persist($row);
        }
        foreach ([$airbag, $paranoidAndroid, $exitMusic, $mysterons] as $row) {
            $entityManager->persist($row);
        }
        $entityManager->persist($ada);
        $entityManager->persist($morningMix);
        foreach ([$entryAirbag, $entryExitMusic, $entryParanoidAndroid] as $row) {
            $entityManager->persist($row);
        }
        foreach ([$favTrack, $favAlbum, $favArtist] as $row) {
            $entityManager->persist($row);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
