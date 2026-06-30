<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\PlaylistEntry;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User;

/**
 * Extra, DEMO-ONLY catalogue data layered on top of the deterministic {@see Seed}.
 *
 * The base {@see Seed} is the TEST fixture — its exact counts/ids back the bundle's
 * functional suite, so it must stay minimal. This class is referenced ONLY by the
 * served front controller ({@see \haddowg\JsonApiBundle\Examples\MusicCatalog public/index.php}),
 * never by a test, so it can flesh the catalogue out to something worth browsing in
 * the live demo (a couple dozen artists, a few albums each, several playlists)
 * without disturbing a single assertion. Its rows take ids AFTER the base seed's, so
 * the base ids the tests assert (artist 1 = Radiohead, album 1 = OK Computer, …) are
 * untouched.
 *
 * Everything is generated deterministically from fixed word pools (no randomness),
 * so a rebuilt image always serves the same catalogue. New playlists are owned by
 * the seeded `ada@example.com` so the live demo's Bearer token can manage them.
 */
final class DemoSeed
{
    /** Curated artist names — the headline browse content. */
    private const array ARTISTS = [
        'Neon Vientiane', 'The Paper Lanterns', 'Cascade Theory', 'Marble Arch',
        'Low Tide Club', 'Ferns & Static', 'Aurora Mensch', 'The Glasshouse',
        'Velvet Cartography', 'Northern Signals', 'Sundial', 'Echo & the Tide',
        'Carbon Lilies', 'The Midnight Civic', 'Halcyon Drive', 'Pale Atlas',
        'Cobalt Hours', 'The Wandering Coast', 'Slow Continental', 'Amber Frequency',
        'Tessellate', 'The Quiet Engine',
    ];

    /** Album-title word pools (combined deterministically per album). */
    private const array ALBUM_A = [
        'Afterglow', 'Paper', 'Lantern', 'Slow', 'Northern', 'Velvet', 'Hollow', 'Golden',
        'Distant', 'Glass', 'Static', 'Wild', 'Quiet', 'Endless', 'Marble', 'Cobalt',
        'Pale', 'Amber', 'Lunar', 'Crimson', 'Silent', 'Electric',
    ];
    private const array ALBUM_B = [
        'Cities', 'Horizons', 'Seasons', 'Rooms', 'Letters', 'Machines', 'Gardens', 'Tides',
        'Maps', 'Signals', 'Avenues', 'Hours', 'Mornings', 'Currents', 'Stories', 'Frequencies',
        'Reflections', 'Echoes', 'Orbits', 'Fields', 'Harbours', 'Lights',
    ];

    /** Track-title word pools. */
    private const array TRACK_A = [
        'Midnight', 'Velvet', 'Electric', 'Golden', 'Silent', 'Crimson', 'Neon', 'Hollow',
        'Distant', 'Paper', 'Glass', 'Ember', 'Lunar', 'Static', 'Wild', 'Frozen',
        'Scarlet', 'Quiet', 'Broken', 'Endless',
    ];
    private const array TRACK_B = [
        'Echoes', 'Horizon', 'Machine', 'Dream', 'Rain', 'Cities', 'Hearts', 'Tides',
        'Signals', 'Ghosts', 'Avenue', 'Light', 'Maze', 'Orbit', 'Fire', 'Waves',
        'Static', 'Roads', 'Skies', 'Hours',
    ];

    private const array GENRES = [
        'indie', 'electronic', 'rock', 'ambient', 'synthpop', 'dream-pop',
        'post-rock', 'trip-hop', 'folk', 'shoegaze',
    ];

    private const array PLAYLISTS = [
        'Late Night Drive', 'Focus Hours', 'Sunday Coffee', 'Workout Surge', 'Rainy Day',
    ];

    public static function into(EntityManagerInterface $entityManager): void
    {
        // The base seed clears the EM, so re-load the playlist owner from the DB.
        $ada = $entityManager->getRepository(User::class)->findOneBy(['email' => 'ada@example.com']);
        \assert($ada instanceof User);

        $base = new \DateTimeImmutable('2018-01-01T09:00:00+00:00');
        $tracks = [];
        $trackSeq = 0;

        foreach (self::ARTISTS as $artistIndex => $name) {
            $artist = new Artist(
                name: $name,
                slug: self::slug($name),
                website: null,
                bio: \sprintf('%s — a %s act in the demo catalogue.', $name, self::GENRES[$artistIndex % \count(self::GENRES)]),
                trackCount: 0,
                createdAt: $base->modify(\sprintf('+%d days', $artistIndex * 11)),
            );
            $entityManager->persist($artist);

            // Two albums each (a couple of dozen artists × 2 keeps the catalogue under the
            // 50-per-page cap, so the demo's `page[size]=50` shows every album in one go).
            for ($a = 0; $a < 2; $a++) {
                $titleIndex = $artistIndex * 2 + $a;
                $album = new Album(
                    title: self::ALBUM_A[$titleIndex % \count(self::ALBUM_A)] . ' ' . self::ALBUM_B[($titleIndex * 7) % \count(self::ALBUM_B)],
                    averageRating: \round(6.5 + (($titleIndex * 13) % 35) / 10, 1),
                    releasedAt: $base->modify(\sprintf('+%d months', $titleIndex * 3)),
                    published: true,
                    explicit: false,
                    status: 'released',
                    availableFrom: $base->modify(\sprintf('+%d months', $titleIndex * 3)),
                    availableUntil: new \DateTimeImmutable('2035-12-31'),
                    releaseInfo: ['label' => 'Demo Records', 'catalogueNumber' => \sprintf('DEMO-%03d', $titleIndex + 1)],
                    artist: $artist,
                );
                $entityManager->persist($album);

                for ($n = 1; $n <= 4; $n++) {
                    $track = new Track(
                        title: self::TRACK_A[$trackSeq % \count(self::TRACK_A)] . ' ' . self::TRACK_B[($trackSeq * 5) % \count(self::TRACK_B)],
                        trackNumber: $n,
                        length_seconds: 150 + (($trackSeq * 17) % 180),
                        explicit: false,
                        genres: [self::GENRES[$trackSeq % \count(self::GENRES)]],
                        previewOffset: null,
                        album: $album,
                    );
                    $entityManager->persist($track);
                    $tracks[] = $track;
                    $artist->trackCount++;
                    $trackSeq++;
                }
            }
        }

        // A handful of ada-owned playlists, each a deterministic slice of the catalogue
        // (enough tracks that the demo's larger page size has something to show).
        foreach (self::PLAYLISTS as $playlistIndex => $title) {
            $playlist = new Playlist(
                id: \sprintf('00000000-0000-4000-8000-0000000001%02d', $playlistIndex + 1),
                title: $title,
                slug: self::slug($title),
                public: $playlistIndex % 2 === 0,
                externalId: null,
                owner: $ada,
            );
            $entityManager->persist($playlist);

            $size = 8 + $playlistIndex * 2; // 8, 10, 12, …
            $addedAt = new \DateTimeImmutable('2024-06-01T12:00:00+00:00');
            for ($position = 1; $position <= $size; $position++) {
                $track = $tracks[($playlistIndex * 9 + $position * 3) % \count($tracks)];
                $entry = new PlaylistEntry(
                    playlist: $playlist,
                    track: $track,
                    position: $position,
                    // Stored well above each position so the `weight >= position` rule holds
                    // and a later partial reorder need not re-send it (matches the base seed).
                    weight: 100,
                    addedAt: $addedAt->modify(\sprintf('+%d hours', $position)),
                );
                $entityManager->persist($entry);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /** A simple ASCII slug (lower-case, non-alphanumerics → single hyphens). */
    private static function slug(string $value): string
    {
        return \trim(\preg_replace('/[^a-z0-9]+/', '-', \strtolower($value)) ?? '', '-');
    }
}
