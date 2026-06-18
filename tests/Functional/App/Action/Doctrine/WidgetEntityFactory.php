<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see WidgetEntity}: the Doctrine custom-action suite seeds two
 * `actionWidgets` rows through it (ids 1, 2 in insertion order), mirroring the
 * in-memory seed.
 *
 * @extends PersistentObjectFactory<WidgetEntity>
 */
final class WidgetEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return WidgetEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => 'A widget',
            'published' => false,
        ];
    }
}
