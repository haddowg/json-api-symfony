<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Seeds the in-memory `cursorGroups` provider from the shared {@see CursorGroupFixtures},
 * the witness half of the inverse-FK cursor (keyset) include dual-provider conformance. Each
 * group's members are the LIVE {@see CursorWidget} objects out of the `cursorWidgets`
 * provider's store (not copies), mirroring how the Doctrine groups associate the seeded
 * widget rows through the owning `group_id` FK.
 */
final class CursorGroupProviderFactory
{
    /**
     * A persister sharing the provider's store, so the write-capable `cursorGroups` resource
     * is servable (the warm-up guard requires a persister for a write-exposing type).
     */
    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('cursorGroups', $provider->store(), static fn(): CursorGroup => new CursorGroup());
    }

    public static function create(InMemoryDataProvider $widgetProvider): InMemoryDataProvider
    {
        $store = $widgetProvider->store();

        $groups = [];
        foreach (CursorGroupFixtures::data() as $id => $widgetIds) {
            $widgets = [];
            foreach ($widgetIds as $widgetId) {
                $widget = $store->find((string) $widgetId);
                \assert($widget instanceof CursorWidget);
                $widgets[] = $widget;
            }

            $groups[(string) $id] = new CursorGroup((int) $id, $widgets);
        }

        return new InMemoryDataProvider('cursorGroups', $groups);
    }
}
