<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class CrateFactory
{
    /**
     * The crates both kernels seed, in id order.
     *
     * @return list<array{name: string, status: string, weight: int, lifecycle: string}>
     */
    public static function rows(): array
    {
        return [
            ['name' => 'alpha', 'status' => 'open', 'weight' => 3, 'lifecycle' => 'live'],
            ['name' => 'beta', 'status' => 'closed', 'weight' => 5, 'lifecycle' => 'live'],
            ['name' => 'gamma', 'status' => 'open', 'weight' => 7, 'lifecycle' => 'live'],
            ['name' => 'delta', 'status' => 'open', 'weight' => 3, 'lifecycle' => 'retired'],
            ['name' => 'epsilon', 'status' => 'closed', 'weight' => 9, 'lifecycle' => 'live'],
            ['name' => 'zeta', 'status' => 'open', 'weight' => 1, 'lifecycle' => 'live'],
        ];
    }

    public static function createProvider(): InMemoryDataProvider
    {
        // Seeded out of id order, so each() has to sort.
        $crates = [];
        foreach (\array_reverse(self::rows(), true) as $index => $row) {
            $id = $index + 1;
            $crates[(string) $id] = new Crate($id, $row['name'], $row['status'], $row['weight'], $row['lifecycle']);
        }

        return new InMemoryDataProvider(
            'filterCrates',
            $crates,
            static function (object $crate): string {
                \assert($crate instanceof Crate);

                return (string) $crate->id;
            },
        );
    }
}
