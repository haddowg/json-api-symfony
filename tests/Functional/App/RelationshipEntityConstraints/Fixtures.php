<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The seeded graph both kernels serve. User 2's name breaks the name's length
 * constraint. The in-memory stores share one snapshot coordinator so an atomic
 * rollback restores the whole graph.
 */
final class Fixtures
{
    private readonly InMemorySnapshotCoordinator $coordinator;

    private ?InMemoryDataProvider $companies = null;

    private ?InMemoryDataProvider $users = null;

    private ?InMemoryDataProvider $teams = null;

    public function __construct()
    {
        $this->coordinator = new InMemorySnapshotCoordinator();
    }

    /**
     * @return array{companies: array<int, Company>, users: array<int, IntegrationUser>, teams: array<int, Team>}
     */
    public static function graph(): array
    {
        $acme = new Company(1, 'Acme');
        $globex = new Company(2, 'Globex');
        $initech = new Company(3, 'Initech');

        return [
            'companies' => ['1' => $acme, '2' => $globex, '3' => $initech],
            'users' => [
                '1' => new IntegrationUser(1, 'Ada', $acme, [$acme]),
                '2' => new IntegrationUser(2, 'A stored name too long', $acme, [$acme, $globex]),
            ],
            'teams' => ['1' => new Team(1, [$acme])],
        ];
    }

    public function companies(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->companies !== null);

        return $this->companies;
    }

    public function users(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->users !== null);

        return $this->users;
    }

    public function teams(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->teams !== null);

        return $this->teams;
    }

    public function companiesPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('companies', $this->companies()->store(), static fn(): Company => new Company());
    }

    public function usersPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('integration-users', $this->users()->store(), static fn(): IntegrationUser => new IntegrationUser(), $this->resolver());
    }

    public function teamsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('teams', $this->teams()->store(), static fn(): Team => new Team(), $this->resolver());
    }

    private function resolver(): \Closure
    {
        $companies = $this->companies();

        return static fn(string $type, string $id): ?object => $type === 'companies' ? $companies->store()->find($id) : null;
    }

    private function seed(): void
    {
        if ($this->companies !== null) {
            return;
        }

        $graph = self::graph();
        $users = [];
        foreach ($graph['users'] as $id => $user) {
            // The in-memory persister writes a to-many as a plain list.
            $user->companies = [...$user->companies];
            $users[$id] = $user;
        }
        $teams = [];
        foreach ($graph['teams'] as $id => $team) {
            $team->companies = [...$team->companies];
            $teams[$id] = $team;
        }

        $this->companies = $this->provider('companies', $graph['companies']);
        $this->users = $this->provider('integration-users', $users);
        $this->teams = $this->provider('teams', $teams);
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
                $id = $item instanceof Company || $item instanceof IntegrationUser || $item instanceof Team ? $item->id : null;

                return $id !== null ? (string) $id : '';
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Company || $item instanceof IntegrationUser || $item instanceof Team);
                $item->id = (int) $id;
            },
            coordinator: $this->coordinator,
        );
    }
}
