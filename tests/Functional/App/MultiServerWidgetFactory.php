<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory providers for the multi-server witness (ADR 0034),
 * one per JSON:API type, each seeded with a single widget. The SPI resolves by type,
 * not by server — a type exposed on two servers reads from the one provider.
 */
final class MultiServerWidgetFactory
{
    public static function persister(string $type, InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister($type, $provider->store(), static fn(): MultiServerWidget => new MultiServerWidget());
    }

    public static function createPublic(): InMemoryDataProvider
    {
        return self::provider('public-widgets', new MultiServerWidget('p1', 'Public'));
    }

    public static function createAdmin(): InMemoryDataProvider
    {
        return self::provider('admin-widgets', new MultiServerWidget('a1', 'Admin'));
    }

    public static function createShared(): InMemoryDataProvider
    {
        return self::provider('shared-widgets', new MultiServerWidget('s1', 'Shared'));
    }

    private static function provider(string $type, MultiServerWidget $widget): InMemoryDataProvider
    {
        return new InMemoryDataProvider($type, [$widget->id => $widget], static function (object $item): string {
            \assert($item instanceof MultiServerWidget);

            return $item->id;
        });
    }
}
