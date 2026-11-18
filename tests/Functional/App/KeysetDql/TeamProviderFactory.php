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

        return new InMemoryDataProvider('teams', $teams);
    }
}
