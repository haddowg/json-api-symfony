<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory `widgets` provider for the serialize-only witness, seeded
 * with one widget carrying a {@see Color}. The colors type needs no provider — its
 * objects come from the parent widget.
 */
final class WidgetFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        $widgets = ['w1' => new Widget('w1', 'Sprocket', new Color('c1', 'Red'))];

        return new InMemoryDataProvider('widgets', $widgets, static function (object $item): string {
            \assert($item instanceof Widget);

            return $item->id;
        });
    }
}
