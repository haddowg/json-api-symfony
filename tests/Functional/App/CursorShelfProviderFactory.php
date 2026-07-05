<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Seeds the in-memory `cursorShelves` provider from the shared
 * {@see CursorShelfFixtures}, the witness half of the related-collection cursor
 * (keyset) dual-provider conformance. Each shelf's members are the LIVE
 * {@see CursorWidget} objects out of the `cursorWidgets` provider's store (not
 * copies), mirroring how the Doctrine shelves associate the seeded widget rows.
 */
final class CursorShelfProviderFactory
{
    /**
     * A persister sharing the provider's store, so the write-capable
     * `cursorShelves` resource is servable (the warm-up guard requires a
     * persister for a write-exposing type).
     */
    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('cursorShelves', $provider->store(), static fn(): CursorShelf => new CursorShelf());
    }

    public static function create(InMemoryDataProvider $widgetProvider): InMemoryDataProvider
    {
        $store = $widgetProvider->store();

        $shelves = [];
        foreach (CursorShelfFixtures::data() as $id => $widgetIds) {
            $widgets = [];
            foreach ($widgetIds as $widgetId) {
                $widget = $store->find((string) $widgetId);
                \assert($widget instanceof CursorWidget);
                $widgets[] = $widget;
            }

            $shelves[(string) $id] = new CursorShelf((int) $id, $widgets);
        }

        return new InMemoryDataProvider('cursorShelves', $shelves);
    }
}
