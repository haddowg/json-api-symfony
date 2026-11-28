<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory `playlists` provider for the pivot boundary witness, seeded
 * with one playlist carrying two tracks at positions 1 and 2, its pivot rows kept beside it.
 * The `tracks` values come off the parent.
 */
final class PivotPlaylistFactory
{
    public static function createSetlistsProvider(): InMemoryDataProvider
    {
        $setlist = new PivotPlaylist('1', 'Set', [new PivotTrack('1', 'Intro')]);
        $provider = new InMemoryDataProvider('setlists', ['1' => $setlist]);
        $provider->store()->writePivotRows($setlist, 'tracks', ['1' => ['position' => 1]]);

        return $provider;
    }

    public static function createProvider(): InMemoryDataProvider
    {
        $playlists = [
            '1' => new PivotPlaylist('1', 'Set', [
                new PivotTrack('1', 'Intro'),
                new PivotTrack('2', 'Outro'),
            ]),
        ];

        $provider = new InMemoryDataProvider('playlists', $playlists, static function (object $item): string {
            \assert($item instanceof PivotPlaylist);

            return $item->id;
        });
        $provider->store()->writePivotRows($playlists['1'], 'tracks', ['1' => ['position' => 1], '2' => ['position' => 2]]);

        return $provider;
    }

    /**
     * The far `tracks` provider — the related-collection endpoint resolves a provider
     * for the related type even though the members are read off the parent.
     */
    public static function createTracksProvider(): InMemoryDataProvider
    {
        $tracks = [
            '1' => new PivotTrack('1', 'Intro'),
            '2' => new PivotTrack('2', 'Outro'),
        ];

        return new InMemoryDataProvider('tracks', $tracks, static function (object $item): string {
            \assert($item instanceof PivotTrack);

            return $item->id;
        });
    }

    public static function createPersister(InMemoryDataProvider $provider, InMemoryDataProvider $tracks): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'playlists',
            $provider->store(),
            static fn(): PivotPlaylist => new PivotPlaylist('', '', []),
            static fn(string $type, string $id): ?object => $type === 'tracks' ? $tracks->store()->find($id) : null,
        );
    }

    public static function createTracksPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('tracks', $provider->store(), static fn(): PivotTrack => new PivotTrack('', ''));
    }
}
