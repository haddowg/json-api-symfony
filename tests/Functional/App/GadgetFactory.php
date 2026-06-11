<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `gadget` pair for the custom serializer/hydrator
 * witness, seeded with one gadget so a read can be asserted to flow through the
 * override serializer.
 */
final class GadgetFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        $gadgets = ['g1' => new Gadget('g1', 'Original')];

        return new InMemoryDataProvider('gadget', $gadgets, static function (object $item): string {
            \assert($item instanceof Gadget);

            return $item->id;
        });
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('gadget', $provider->store(), static fn(): Gadget => new Gadget());
    }
}
