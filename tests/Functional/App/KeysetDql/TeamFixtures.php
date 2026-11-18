<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

/**
 * Six teams, each with a nullable base and bonus and its members' scores. Teams 2 and 5
 * have no members, so their top score is null. Teams 3 and 4 have no base, so their
 * tier is null. Team 3 has neither base nor bonus, so its points are null.
 */
final class TeamFixtures
{
    /**
     * @return array<int, array{name: string, base: ?int, bonus: ?int, scores: list<int>}>
     */
    public static function data(): array
    {
        return [
            1 => ['name' => 'Alpha', 'base' => 5, 'bonus' => null, 'scores' => [3, 9]],
            2 => ['name' => 'Bravo', 'base' => 12, 'bonus' => 4, 'scores' => []],
            3 => ['name' => 'Charlie', 'base' => null, 'bonus' => null, 'scores' => [7]],
            4 => ['name' => 'Delta', 'base' => null, 'bonus' => 8, 'scores' => [9]],
            5 => ['name' => 'Echo', 'base' => 10, 'bonus' => null, 'scores' => []],
            6 => ['name' => 'Foxtrot', 'base' => 3, 'bonus' => 1, 'scores' => [2]],
        ];
    }
}
