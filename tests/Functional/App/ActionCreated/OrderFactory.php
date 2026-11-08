<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionCreated;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class OrderFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'createdOrders',
            ['1' => new Order('1', 'A-1'), '2' => new Order('2', 'A-2')],
            static function (object $order): string {
                \assert($order instanceof Order);

                return $order->id;
            },
        );
    }
}
