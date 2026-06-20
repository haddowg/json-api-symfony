<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see MedalEntity}: seeds the related `medals` rows of the
 * request-aware-predicates Doctrine fixture.
 *
 * @extends PersistentObjectFactory<MedalEntity>
 */
final class MedalEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return MedalEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'title' => self::faker()->word(),
        ];
    }
}
