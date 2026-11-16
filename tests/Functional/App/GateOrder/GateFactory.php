<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * The in-memory `gateWidgets`, seeded as {@see self::seed()} describes.
 */
final class GateFactory
{
    private static ?InMemoryDataProvider $widgets = null;

    public static function createProvider(): InMemoryDataProvider
    {
        return self::widgets();
    }

    public static function createPersister(): InMemoryDataPersister
    {
        $widgets = self::widgets();

        return new InMemoryDataPersister(
            'gateWidgets',
            $widgets->store(),
            static fn(): GateWidget => new GateWidget(),
            static fn(string $type, string $id): ?object => $type === 'gateWidgets' ? $widgets->store()->find($id) : null,
        );
    }

    public static function reset(): void
    {
        self::$widgets = null;
    }

    /**
     * Widget 1 "mine" and 3 "secret" belong to `user`, 2 "theirs" and 4 "child" to `other`.
     * Widget 1's partner is 2, and 4 is a child of 2.
     *
     * @return array<int, GateWidget>
     */
    public static function seed(): array
    {
        $widgets = [
            1 => new GateWidget('mine', 'user'),
            2 => new GateWidget('theirs', 'other'),
            3 => new GateWidget('secret', 'user'),
            4 => new GateWidget('child', 'other'),
        ];
        $widgets[1]->partner = $widgets[2];
        $widgets[4]->parent = $widgets[2];
        $widgets[2]->children->add($widgets[4]);

        return $widgets;
    }

    private static function widgets(): InMemoryDataProvider
    {
        if (self::$widgets === null) {
            $items = [];
            foreach (self::seed() as $id => $widget) {
                $widget->id = $id;
                $items[(string) $id] = $widget;
            }

            self::$widgets = new InMemoryDataProvider(
                'gateWidgets',
                $items,
                static fn(object $item): string => (string) ($item instanceof GateWidget ? $item->id : ''),
                static function (object $item, string $id): void {
                    \assert($item instanceof GateWidget);
                    $item->id = (int) $id;
                },
            );
        }

        return self::$widgets;
    }
}
