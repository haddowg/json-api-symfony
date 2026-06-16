<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Favorite;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Library;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User;

/**
 * Plain, deterministic fixtures (no Foundry) — the Doctrine twin of core's
 * in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Seed.php Seed}.
 * {@see into()} persists a coherent object graph for all seven entity-backed
 * types through the {@see EntityManagerInterface}: artists own albums, albums own
 * tracks, tracks belong to playlists, a user owns playlists + a library, and the
 * favorites/library carry their polymorphic targets.
 *
 * Counts mirror core so the same values back the query/relationship tests: album
 * "1" has three tracks (so a per-relation perPage=2 paginator yields two pages),
 * the slug/title/genre values back the filter tests, and the album date pair backs
 * the directional CompareField. Album "3" is unpublished so the
 * `PublishedAlbumsExtension` scope (built next phase) has a row to hide.
 *
 * The graph is built in passes: construct every row, wire the references, then
 * persist + flush once. The polymorphic targets are stored as
 * `targetType`/`targetId` pairs ({@see Favorite}) and the library's items are left
 * to the custom provider to resolve — so only the pointers are persisted here.
 */
final class Seed
{
    public static function into(EntityManagerInterface $entityManager): void
    {
        // --- Artists ----------------------------------------------------------
        $radiohead = new Artist(
            id: '1',
            name: 'Radiohead',
            slug: 'radiohead',
            website: 'https://radiohead.com',
            bio: 'An English rock band formed in Abingdon.',
            trackCount: 3,
            createdAt: new \DateTimeImmutable('2001-05-01T09:00:00+00:00'),
        );
        $portishead = new Artist(
            id: '2',
            name: 'Portishead',
            slug: 'portishead',
            website: null,
            bio: null,
            trackCount: 1,
            createdAt: new \DateTimeImmutable('2002-08-15T12:30:00+00:00'),
        );

        // --- Albums (album "3" is unpublished — the extension scope hides it) --
        $okComputer = new Album(
            id: '1',
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
            id: '2',
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
            id: '3',
            title: 'Unreleased Sessions',
            averageRating: null,
            releasedAt: null,
            published: false,
            explicit: false,
            availableFrom: null,
            availableUntil: null,
            releaseInfo: null,
            artist: $radiohead,
        );

        // --- Tracks -----------------------------------------------------------
        $airbag = new Track(
            id: '1',
            title: 'Airbag',
            trackNumber: 1,
            length_seconds: 284,
            explicit: false,
            genres: ['rock', 'alternative'],
            previewOffset: '00:00:30',
            album: $okComputer,
        );
        $paranoidAndroid = new Track(
            id: '2',
            title: 'Paranoid Android',
            trackNumber: 2,
            length_seconds: 383,
            explicit: true,
            genres: ['rock', 'progressive'],
            previewOffset: '00:01:00',
            album: $okComputer,
        );
        $exitMusic = new Track(
            id: '3',
            title: 'Exit Music (For a Film)',
            trackNumber: 3,
            length_seconds: 264,
            explicit: false,
            genres: ['rock'],
            previewOffset: null,
            album: $okComputer,
        );
        $mysterons = new Track(
            id: '4',
            title: 'Mysterons',
            trackNumber: 1,
            length_seconds: 305,
            explicit: false,
            genres: ['trip-hop'],
            previewOffset: '00:00:45',
            album: $dummy,
        );

        // --- Library + User (the OneToOne owning FK is on the user) -----------
        $library = new Library(id: '1');

        $ada = new User(
            id: '1',
            email: 'ada@example.com',
            displayName: 'Ada',
            birthDate: new \DateTimeImmutable('1990-12-10'),
            preferences: ['theme' => 'dark', 'autoplay' => true],
            lastSeenIp: '203.0.113.7',
            password: null,
            library: $library,
        );

        // --- Playlist (client-generated UUID id) ------------------------------
        $morningMix = new Playlist(
            id: '00000000-0000-4000-8000-000000000001',
            title: 'Morning Mix',
            slug: 'morning-mix',
            public: true,
            externalId: '11111111-1111-4111-8111-111111111111',
            owner: $ada,
        );
        // track ↔ playlists (the owning side is on Track)
        $airbag->playlists->add($morningMix);
        $paranoidAndroid->playlists->add($morningMix);

        // --- Favorites (MorphTo: stored as a targetType/targetId pair) ---------
        $favTrack = new Favorite(
            id: '1',
            favoritedAt: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            targetType: 'tracks',
            targetId: '2',
            user: $ada,
        );
        $favAlbum = new Favorite(
            id: '2',
            favoritedAt: new \DateTimeImmutable('2024-02-20T14:30:00+00:00'),
            targetType: 'albums',
            targetId: '1',
            user: $ada,
        );
        $favArtist = new Favorite(
            id: '3',
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
        foreach ([$favTrack, $favAlbum, $favArtist] as $row) {
            $entityManager->persist($row);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
