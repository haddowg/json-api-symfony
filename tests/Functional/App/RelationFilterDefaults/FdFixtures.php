<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Album 1 holds a clean track (1), an explicit one (2), an archived one (3) and one
 * both explicit and archived (4). Album 2 holds a clean track (5).
 */
final class FdFixtures
{
    public const array ALBUMS = [1 => 'First', 2 => 'Second'];

    /**
     * Each track's title, explicit and archived flags, and album.
     */
    public const array TRACKS = [
        1 => ['Alpha', false, false, 1],
        2 => ['Bravo', true, false, 1],
        3 => ['Charlie', false, true, 1],
        4 => ['Delta', true, true, 1],
        5 => ['Echo', false, false, 2],
    ];

    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private ?array $providers = null;

    /**
     * @return array{albums: list<FdAlbum>, tracks: list<FdTrack>}
     */
    public static function graph(): array
    {
        $albums = [];
        foreach (self::ALBUMS as $id => $title) {
            $albums[$id] = new FdAlbum($id, $title);
        }

        $tracks = [];
        foreach (self::TRACKS as $id => [$title, $explicit, $archived, $album]) {
            $track = new FdTrack($id, $title, $explicit, $archived, $albums[$album]);
            $albums[$album]->tracks->add($track);
            $tracks[] = $track;
        }

        return ['albums' => \array_values($albums), 'tracks' => $tracks];
    }

    public function provider(string $type): InMemoryDataProvider
    {
        if ($this->providers === null) {
            $graph = self::graph();
            $identify = static fn(object $item): string => $item instanceof FdAlbum || $item instanceof FdTrack ? (string) $item->id : '';
            $this->providers = [];
            foreach (['albums', 'tracks'] as $name) {
                $byId = [];
                foreach ($graph[$name] as $item) {
                    $byId[(string) $item->id] = $item;
                }
                $this->providers[$name] = new InMemoryDataProvider($name, $byId, $identify);
            }
        }

        return $this->providers[$type];
    }
}
