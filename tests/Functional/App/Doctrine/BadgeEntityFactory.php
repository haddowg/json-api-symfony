<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see BadgeEntity}: seeds the `badges` row of the
 * request-aware-predicates Doctrine fixture (the `medals` membership is wired by
 * the seeding trait after the medals exist).
 *
 * @extends PersistentObjectFactory<BadgeEntity>
 */
final class BadgeEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return BadgeEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'name' => self::faker()->word(),
        ];
    }
}
