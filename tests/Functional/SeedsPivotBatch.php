<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\TrackEntity;

/**
 * Seeds twelve playlists over six shared tracks for the pivot batch suite.
 *
 * Playlist `n` (1-based) holds three tracks, picked so neighbouring playlists share
 * members: track `n`, `n + 1` and `n + 2` (wrapping over the six), at positions 3, 1
 * and 2. So position order never matches track id order, and a batch that kept one
 * row per track across the page would lose a playlist's membership. Every
 * playlist also holds the track titled "Hidden" at position 4, which
 * {@see App\Doctrine\HiddenTracksExtension} scopes out. Playlist 12 holds track 1
 * twice, at positions 5 and 6, for duplicate membership.
 */
trait SeedsPivotBatch
{
    protected const int PLAYLISTS = 12;

    protected const array TITLES = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'];

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $tracks = [];
        foreach (self::TITLES as $title) {
            $tracks[] = $track = new TrackEntity(title: $title);
            $entityManager->persist($track);
        }
        $hidden = new TrackEntity(title: 'Hidden');
        $entityManager->persist($hidden);

        for ($n = 1; $n <= self::PLAYLISTS; ++$n) {
            $playlist = new PlaylistEntity(name: 'Playlist ' . $n);
            $entityManager->persist($playlist);

            $rows = [
                [$tracks[($n - 1) % 6], 3],
                [$tracks[$n % 6], 1],
                [$tracks[($n + 1) % 6], 2],
                [$hidden, 4],
            ];
            if ($n === self::PLAYLISTS) {
                $rows[] = [$tracks[0], 5];
                $rows[] = [$tracks[0], 6];
            }

            foreach ($rows as [$track, $position]) {
                $entityManager->persist(new PlaylistTrackEntity(
                    playlist: $playlist,
                    track: $track,
                    position: $position,
                    weight: 100,
                    addedAt: new \DateTimeImmutable(\sprintf('2024-%02d-%02dT00:00:00+00:00', $n, $position)),
                ));
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
