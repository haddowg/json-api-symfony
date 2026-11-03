<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

/**
 * Six campaigns and their `cap` placeholder, stored as text. Read as text, `120` sorts
 * before `30` and `5`, so only a numeric cast orders them 5, 5, 30, 120. Campaigns 3
 * and 6 have no cap, and 2 and 5 tie on it.
 */
final class CampaignFixtures
{
    /**
     * @return array<int, array{name: string, values: array<string, string>}>
     */
    public static function data(): array
    {
        return [
            1 => ['name' => 'Delta', 'values' => ['cap' => '30']],
            2 => ['name' => 'Foxtrot', 'values' => ['cap' => '5']],
            3 => ['name' => 'Alpha', 'values' => []],
            4 => ['name' => 'Charlie', 'values' => ['cap' => '120']],
            5 => ['name' => 'Bravo', 'values' => ['cap' => '5']],
            6 => ['name' => 'Echo', 'values' => []],
        ];
    }
}
