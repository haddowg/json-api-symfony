<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory providers/persister for the operation-exposure witness
 * (ADR 0025): a read-only `ledgers` provider (no persister), a create-only
 * `signals` provider + persister sharing one store (so a POST is persisted and
 * the SPI resolves a write), and a read-only `beacons` provider for the routed
 * standalone serializer type. Seed objects cannot be service-config literals, so
 * the kernel registers these factory methods.
 */
final class OperationsFactory
{
    public static function createLedgersProvider(): InMemoryDataProvider
    {
        $ledgers = ['l1' => new Ledger('l1', 'General')];

        return new InMemoryDataProvider('ledgers', $ledgers, static function (object $item): string {
            \assert($item instanceof Ledger);

            return $item->id;
        });
    }

    public static function createSignalsProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('signals', [], static function (object $item): string {
            \assert($item instanceof Signal);

            return $item->id;
        });
    }

    public static function createSignalsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('signals', $provider->store(), static fn(): Signal => new Signal());
    }

    public static function createBeaconsProvider(): InMemoryDataProvider
    {
        $beacons = ['b1' => new Beacon('b1', 'North')];

        return new InMemoryDataProvider('beacons', $beacons, static function (object $item): string {
            \assert($item instanceof Beacon);

            return $item->id;
        });
    }
}
