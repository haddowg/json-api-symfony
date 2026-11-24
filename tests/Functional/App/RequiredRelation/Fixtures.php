<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The seeded graph both kernels serve: two members, and task 1 owned and watched by
 * member 1. The in-memory stores share one snapshot coordinator so an atomic rollback
 * restores the whole graph.
 */
final class Fixtures
{
    private readonly InMemorySnapshotCoordinator $coordinator;

    private ?InMemoryDataProvider $members = null;

    private ?InMemoryDataProvider $tasks = null;

    public function __construct()
    {
        $this->coordinator = new InMemorySnapshotCoordinator();
    }

    /**
     * @return array{members: array<int, Member>, tasks: array<int, Task>}
     */
    public static function graph(): array
    {
        $ada = new Member(1, 'Ada');
        $grace = new Member(2, 'Grace');

        return [
            'members' => ['1' => $ada, '2' => $grace],
            'tasks' => ['1' => new Task('1', 'Plan', $ada, [$ada])],
        ];
    }

    public function members(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->members !== null);

        return $this->members;
    }

    public function tasks(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->tasks !== null);

        return $this->tasks;
    }

    public function membersPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('members', $this->members()->store(), static fn(): Member => new Member());
    }

    public function tasksPersister(): InMemoryDataPersister
    {
        $members = $this->members();

        return new InMemoryDataPersister(
            'tasks',
            $this->tasks()->store(),
            static function (): Task {
                $task = new Task();
                // The in-memory persister writes a to-many as a plain list.
                $task->watchers = [];

                return $task;
            },
            static fn(string $type, string $id): ?object => $type === 'members' ? $members->store()->find($id) : null,
        );
    }

    private function seed(): void
    {
        if ($this->members !== null) {
            return;
        }

        $graph = self::graph();
        $tasks = [];
        foreach ($graph['tasks'] as $id => $task) {
            $task->watchers = [...$task->watchers];
            $tasks[$id] = $task;
        }

        $this->members = $this->provider('members', $graph['members']);
        $this->tasks = $this->provider('tasks', $tasks);
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
                $id = $item instanceof Member || $item instanceof Task ? $item->id : null;

                return $id !== null ? (string) $id : '';
            },
            static function (object $item, string $id): void {
                if ($item instanceof Task) {
                    $item->id = $id;

                    return;
                }
                \assert($item instanceof Member);
                $item->id = (int) $id;
            },
            coordinator: $this->coordinator,
        );
    }
}
