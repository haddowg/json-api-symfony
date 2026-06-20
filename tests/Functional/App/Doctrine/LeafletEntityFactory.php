<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see LeafletEntity}: seeds the primary `leaflets` rows of the
 * strict-sparse-fieldset Doctrine fixture.
 *
 * @extends PersistentObjectFactory<LeafletEntity>
 */
final class LeafletEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return LeafletEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'title' => self::faker()->sentence(3),
            'secret' => self::faker()->word(),
            'internalRef' => self::faker()->word(),
        ];
    }
}
