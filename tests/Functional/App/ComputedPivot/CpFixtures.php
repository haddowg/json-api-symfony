<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use Doctrine\Common\Collections\ArrayCollection;
use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The seeded graph both kernels serve: three members, crew 1 ("Reds") with Ada and Linus,
 * crew 2 ("Blues") with Ada and Grace, and on Doctrine Ada's and Grace's shifts on crew 1's
 * staff and Linus's on crew 2's. In memory, `staff` reads a crew's members, and its pivot rows
 * hold Ada's and Linus's shifts on crew 1 and Ada's and Grace's on crew 2. Ships 1 and 3 sail with the Reds, ship 2 with the Blues,
 * and fleet 1 holds ships 1 and 2 with ship 3 as its flagship, names ship 2 and crew 1
 * as its vessels, and has Grace as its one hand. Linus serves on ship 1, Ada and Grace on ship 2.
 */
final class CpFixtures
{
    private readonly InMemorySnapshotCoordinator $coordinator;

    private ?InMemoryDataProvider $members = null;

    private ?InMemoryDataProvider $crews = null;

    private ?InMemoryDataProvider $ships = null;

    private ?InMemoryDataProvider $fleets = null;

    public function __construct()
    {
        $this->coordinator = new InMemorySnapshotCoordinator();
    }

    /**
     * @return array{members: array<int, CpMember>, crews: array<int, CpCrew>, shifts: list<CpShift>, ships: array<int, CpShip>, fleets: array<int, CpFleet>}
     */
    public static function graph(): array
    {
        $ada = new CpMember(1, 'Ada');
        $grace = new CpMember(2, 'Grace');
        $linus = new CpMember(3, 'Linus');
        $reds = new CpCrew('1', 'Reds', [$ada, $linus]);
        $blues = new CpCrew('2', 'Blues', [$ada, $grace]);

        $home = new CpFleet('1', 'Home');
        $ark = new CpShip('1', 'Ark', $reds, $ada, $home, [$linus]);
        $bark = new CpShip('2', 'Bark', $blues, $grace, $home, [$ada, $grace]);
        $cog = new CpShip('3', 'Cog', $reds);
        $home->ships = new ArrayCollection([$ark, $bark]);
        $home->flagship = $cog;
        $home->vessels = [['cp-ships', '2'], ['cp-crews', '1']];
        $home->hands = new ArrayCollection([$grace]);

        return [
            'members' => ['1' => $ada, '2' => $grace, '3' => $linus],
            'crews' => ['1' => $reds, '2' => $blues],
            'shifts' => [new CpShift($reds, $ada, 2, 'early'), new CpShift($reds, $grace, 5, 'late'), new CpShift($blues, $linus, 4)],
            'ships' => ['1' => $ark, '2' => $bark, '3' => $cog],
            'fleets' => ['1' => $home],
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

    public function ships(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->ships !== null);

        return $this->ships;
    }

    public function fleets(): InMemoryDataProvider
    {
        $this->seed();
        \assert($this->fleets !== null);

        return $this->fleets;
    }

    public function shipsPersister(): InMemoryDataPersister
    {
        $crews = $this->crews();
        $members = $this->members();

        return new InMemoryDataPersister(
            'cp-ships',
            $this->ships()->store(),
            static function (): CpShip {
                $ship = new CpShip();
                // The in-memory persister writes a to-many as a plain list.
                $ship->members = [];

                return $ship;
            },
            static fn(string $type, string $id): ?object => match ($type) {
                'cp-crews' => $crews->store()->find($id),
                'cp-members' => $members->store()->find($id),
                default => null,
            },
        );
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

        foreach ($graph['ships'] as $ship) {
            $ship->members = [...$ship->members];
        }

        $fleets = [];
        foreach ($graph['fleets'] as $id => $fleet) {
            $fleet->ships = [...$fleet->ships];
            $fleet->hands = [...$fleet->hands];
            $fleets[$id] = $fleet;
        }

        $this->members = $this->provider('cp-members', $graph['members']);
        $this->crews = $this->provider('cp-crews', $crews);
        $this->ships = $this->provider('cp-ships', $graph['ships']);
        $this->fleets = $this->provider('cp-fleets', $fleets);

        // The `staff` pivot rows the in-memory store keeps beside each crew, keyed by member id.
        $this->crews->store()->writePivotRows($crews['1'], 'staff', ['1' => ['shift' => 2, 'note' => 'early'], '3' => ['shift' => 5, 'note' => 'late']]);
        $this->crews->store()->writePivotRows($crews['2'], 'staff', ['1' => ['shift' => 4, 'note' => null], '2' => ['shift' => 6, 'note' => null]]);
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
                $id = $item instanceof CpMember || $item instanceof CpCrew || $item instanceof CpShip || $item instanceof CpFleet ? $item->id : null;

                return $id !== null ? (string) $id : '';
            },
            static function (object $item, string $id): void {
                if ($item instanceof CpCrew || $item instanceof CpShip || $item instanceof CpFleet) {
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
