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
}
