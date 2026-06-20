<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see StickerEntity}: seeds the related `stickers` rows of the
 * strict-sparse-fieldset Doctrine fixture.
 *
 * @extends PersistentObjectFactory<StickerEntity>
 */
final class StickerEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return StickerEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'label' => self::faker()->word(),
        ];
    }
}
