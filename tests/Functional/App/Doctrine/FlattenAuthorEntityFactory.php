<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see FlattenAuthorEntity}: seeds an `authors` row of the
 * flattened-attribute (`on()`) Doctrine fixture (bundle ADR 0085).
 *
 * @extends PersistentObjectFactory<FlattenAuthorEntity>
 */
final class FlattenAuthorEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return FlattenAuthorEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'name' => self::faker()->name(),
        ];
    }
}
