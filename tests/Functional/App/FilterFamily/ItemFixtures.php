<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

final class ItemFixtures
{
    /**
     * The items both kernels seed, keyed by id. Two labels carry a comma, so only an
     * array-only list can ask for them whole.
     *
     * @return array<int, array{name: string, label: string, color: string, size: string, weight: int, board: int}>
     */
    public static function items(): array
    {
        return [
            1 => ['name' => 'apple', 'label' => 'a,b', 'color' => 'red', 'size' => 'small', 'weight' => 1, 'board' => 1],
            2 => ['name' => 'banana', 'label' => 'a', 'color' => 'yellow', 'size' => 'large', 'weight' => 2, 'board' => 1],
            3 => ['name' => 'cherry', 'label' => 'b', 'color' => 'red', 'size' => 'large', 'weight' => 3, 'board' => 1],
            4 => ['name' => 'damson', 'label' => 'c,d', 'color' => 'purple', 'size' => 'small', 'weight' => 4, 'board' => 1],
            5 => ['name' => 'elder', 'label' => 'a,b', 'color' => 'purple', 'size' => 'small', 'weight' => 5, 'board' => 2],
            6 => ['name' => 'fig', 'label' => 'd', 'color' => 'green', 'size' => 'large', 'weight' => 6, 'board' => 2],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function boards(): array
    {
        return [1 => 'orchard', 2 => 'hedgerow'];
    }

    /**
     * The items each board pins, keyed by board then item id, with the pin's rank and
     * time. Only the Doctrine store keeps the pivot values.
     *
     * @return array<int, array<int, array{rank: int, pinnedAt: string}>>
     */
    public static function pins(): array
    {
        return [
            1 => [
                1 => ['rank' => 3, 'pinnedAt' => '2025-01-03T00:00:00+00:00'],
                2 => ['rank' => 1, 'pinnedAt' => '2025-01-01T00:00:00+00:00'],
                4 => ['rank' => 2, 'pinnedAt' => '2025-01-05T00:00:00+00:00'],
            ],
            2 => [
                6 => ['rank' => 1, 'pinnedAt' => '2025-01-02T00:00:00+00:00'],
            ],
        ];
    }
}
