<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory `playlists` provider for the pivot boundary witness, seeded
 * with one playlist carrying two tracks. The `tracks` values come off the parent —
 * the related `tracks` type needs no provider of its own.
 */
final class PivotPlaylistFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        $playlists = [
            '1' => new PivotPlaylist('1', 'Set', [
                new PivotTrack('1', 'Intro'),
                new PivotTrack('2', 'Outro'),
            ]),
        ];

        return new InMemoryDataProvider('playlists', $playlists, static function (object $item): string {
            \assert($item instanceof PivotPlaylist);

            return $item->id;
        });
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
}
