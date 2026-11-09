<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The seeded graph both kernels serve: three members, crew 1 ("Reds") with Ada and Linus,
 * and on Doctrine Ada's and Grace's shifts on crew 1's staff.
 */
final class CpFixtures
{
    private readonly InMemorySnapshotCoordinator $coordinator;

    private ?InMemoryDataProvider $members = null;

    private ?InMemoryDataProvider $crews = null;

    public function __construct()
    {
        $this->coordinator = new InMemorySnapshotCoordinator();
    }

    /**
     * @return array{members: array<int, CpMember>, crews: array<int, CpCrew>, shifts: list<CpShift>}
     */
    public static function graph(): array
    {
        $ada = new CpMember(1, 'Ada');
        $grace = new CpMember(2, 'Grace');
        $linus = new CpMember(3, 'Linus');
        $reds = new CpCrew('1', 'Reds', [$ada, $linus]);

        return [
            'members' => ['1' => $ada, '2' => $grace, '3' => $linus],
            'crews' => ['1' => $reds],
            'shifts' => [new CpShift($reds, $ada, 2, 'early'), new CpShift($reds, $grace, 5, 'late')],
        ];
    }

    public function members(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->members !== null);

        return $this->members;
    }

    public function crews(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->crews !== null);

        return $this->crews;
    }

    public function membersPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('cp-members', $this->members()->store(), static fn(): CpMember => new CpMember());
    }

    public function crewsPersister(): InMemoryDataPersister
    {
        $members = $this->members();

        return new InMemoryDataPersister(
            'cp-crews',
            $this->crews()->store(),
            static function (): CpCrew {
                $crew = new CpCrew();
                // The in-memory persister writes a to-many as a plain list.
                $crew->members = [];

                return $crew;
            },
            static fn(string $type, string $id): ?object => $type === 'cp-members' ? $members->store()->find($id) : null,
        );
    }

    private function seed(): void
    {
        if ($this->members !== null) {
            return;
        }

        $graph = self::graph();
        $crews = [];
        foreach ($graph['crews'] as $id => $crew) {
            $crew->members = [...$crew->members];
            $crews[$id] = $crew;
        }

        $this->members = $this->provider('cp-members', $graph['members']);
        $this->crews = $this->provider('cp-crews', $crews);
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
                $id = $item instanceof CpMember || $item instanceof CpCrew ? $item->id : null;

                return $id !== null ? (string) $id : '';
            },
            static function (object $item, string $id): void {
                if ($item instanceof CpCrew) {
                    $item->id = $id;

                    return;
                }
                \assert($item instanceof CpMember);
                $item->id = (int) $id;
            },
            coordinator: $this->coordinator,
        );
    }
}
