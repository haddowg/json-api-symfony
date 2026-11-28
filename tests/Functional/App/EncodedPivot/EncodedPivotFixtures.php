<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EncodedPivot;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Team 1 holds players 1 and 2, wearing shirts 7 and 9. Each record keeps its stored id and
 * each store keys it by its wire id, while the seeded pivot rows key the players by the ids
 * the records keep.
 */
final class EncodedPivotFixtures
{
    private ?InMemoryDataProvider $teams = null;

    private ?InMemoryDataProvider $players = null;

    public function teams(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->teams !== null);

        return $this->teams;
    }

    public function players(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->players !== null);

        return $this->players;
    }

    public function teamsPersister(): InMemoryDataPersister
    {
        $players = $this->players();

        return new InMemoryDataPersister(
            'enc-teams',
            $this->teams()->store(),
            static fn(): object => (object) ['id' => null, 'name' => null, 'players' => []],
            static fn(string $type, string $id): ?object => $type === 'enc-players' ? $players->store()->find($id) : null,
        );
    }

    private function seed(): void
    {
        if ($this->teams !== null) {
            return;
        }

        $players = ['player-1' => (object) ['id' => '1', 'name' => 'Ann'], 'player-2' => (object) ['id' => '2', 'name' => 'Bob']];
        $team = (object) ['id' => '1', 'name' => 'Reds', 'players' => \array_values($players)];

        $this->players = new InMemoryDataProvider('enc-players', $players, static fn(object $item): string => \is_scalar($item->id ?? null) ? 'player-' . $item->id : '');
        $this->teams = new InMemoryDataProvider('enc-teams', ['team-1' => $team], static fn(object $item): string => \is_scalar($item->id ?? null) ? 'team-' . $item->id : '');
        $this->teams->store()->writePivotRows($team, 'players', ['1' => ['shirt' => 7], '2' => ['shirt' => 9]]);
    }
}
