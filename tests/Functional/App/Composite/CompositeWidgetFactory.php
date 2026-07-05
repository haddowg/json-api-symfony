<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `composites` provider (seeded with one widget so a PATCH has a
 * target, and with identify/assign closures so it persists) plus a persister sharing
 * its store, so a create is immediately readable.
 */
final class CompositeWidgetFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'composites',
            ['1' => new CompositeWidget('1', 'Seed', ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1'])],
            static function (object $item): string {
                \assert($item instanceof CompositeWidget);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof CompositeWidget);

                $item->id = $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('composites', $provider->store(), static fn(): CompositeWidget => new CompositeWidget());
    }
}
