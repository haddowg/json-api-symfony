<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationBounds;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The seeded graph both kernels serve: three players, and squad 1 fielding player 1.
 * The in-memory stores share one snapshot coordinator so an atomic rollback restores
 * the whole graph.
 */
final class Fixtures
{
    private readonly InMemorySnapshotCoordinator $coordinator;

    private ?InMemoryDataProvider $players = null;

    private ?InMemoryDataProvider $squads = null;

    public function __construct()
    {
        $this->coordinator = new InMemorySnapshotCoordinator();
    }

    /**
     * @return array{players: array<int, Player>, squads: array<int, Squad>}
     */
    public static function graph(): array
    {
        $ada = new Player(1, 'Ada');
        $grace = new Player(2, 'Grace');
        $linus = new Player(3, 'Linus');

        return [
            'players' => ['1' => $ada, '2' => $grace, '3' => $linus],
            'squads' => ['1' => new Squad('1', 'Reds', [$ada])],
        ];
    }

    public function players(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->players !== null);

        return $this->players;
    }

    public function squads(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->squads !== null);

        return $this->squads;
    }

    public function playersPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('players', $this->players()->store(), static fn(): Player => new Player());
    }

    public function squadsPersister(): InMemoryDataPersister
    {
        $players = $this->players();

        return new InMemoryDataPersister(
            'squads',
            $this->squads()->store(),
            static function (): Squad {
                $squad = new Squad();
                // The in-memory persister writes a to-many as a plain list.
                $squad->players = [];

                return $squad;
            },
            static fn(string $type, string $id): ?object => $type === 'players' ? $players->store()->find($id) : null,
        );
    }

    private function seed(): void
    {
        if ($this->players !== null) {
            return;
        }

        $graph = self::graph();
        $squads = [];
        foreach ($graph['squads'] as $id => $squad) {
            $squad->players = [...$squad->players];
            $squads[$id] = $squad;
        }

        $this->players = $this->provider('players', $graph['players']);
        $this->squads = $this->provider('squads', $squads);
    }

    /**
     * @param array<int, object> $items
     */
    private function provider(string $type, array $items): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            $type,
            $items,
            static function (object $item): string {
                $id = $item instanceof Player || $item instanceof Squad ? $item->id : null;

                return $id !== null ? (string) $id : '';
            },
            static function (object $item, string $id): void {
                if ($item instanceof Squad) {
                    $item->id = $id;

                    return;
                }
                \assert($item instanceof Player);
                $item->id = (int) $id;
            },
            coordinator: $this->coordinator,
        );
    }
}
