<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see CursorWidgetEntity}: the Doctrine cursor (keyset)
 * conformance suite seeds the `cursorWidgets` rows through this with explicit
 * attributes (category/priority/releasedAt), so the seed order matches the
 * shared {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorWidgetFixtures}.
 *
 * @extends PersistentObjectFactory<CursorWidgetEntity>
 */
final class CursorWidgetEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return CursorWidgetEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'category' => 'guide',
            'priority' => null,
            'releasedAt' => null,
        ];
    }
}
