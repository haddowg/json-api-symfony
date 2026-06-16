<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Seeds the in-memory `cursorWidgets` provider from the shared
 * {@see CursorWidgetFixtures}, the witness half of the cursor (keyset)
 * dual-provider conformance. The seed objects cannot be passed as
 * service-configuration literals, so the kernel registers this static factory.
 */
final class CursorWidgetProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        $widgets = [];
        foreach (CursorWidgetFixtures::data() as $id => $row) {
            $widgets[(string) $id] = new CursorWidget(
                (int) $id,
                $row['category'],
                $row['priority'],
                $row['releasedAt'] !== null ? new \DateTimeImmutable($row['releasedAt']) : null,
            );
        }

        return new InMemoryDataProvider('cursorWidgets', $widgets);
    }
}
