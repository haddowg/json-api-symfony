<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class TeamProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        $teams = [];
        foreach (TeamFixtures::data() as $id => $row) {
            $teams[(string) $id] = new Team($id, $row['name'], $row['base'], $row['bonus'], $row['scores']);
        }

        return new InMemoryDataProvider('teams', $teams, sortArms: [new InMemoryTeamSortArm()]);
    }

    /**
     * A league or division, `$type`, holding every team.
     */
    public static function group(string $type): InMemoryDataProvider
    {
        $teams = [];
        foreach (TeamFixtures::data() as $id => $row) {
            $teams[] = new Team($id, $row['name'], $row['base'], $row['bonus'], $row['scores']);
        }

        $group = new Group(1, $teams);
        $provider = new InMemoryDataProvider($type, ['1' => $group], sortArms: [new InMemoryTeamSortArm()]);
        // Each team's `slot` pivot row, its id, as the Doctrine association entity holds it.
        $slots = [];
        foreach (TeamFixtures::data() as $id => $row) {
            $slots[(string) $id] = ['slot' => $id];
        }
        $provider->store()->writePivotRows($group, 'teams', $slots);

        return $provider;
    }
}
