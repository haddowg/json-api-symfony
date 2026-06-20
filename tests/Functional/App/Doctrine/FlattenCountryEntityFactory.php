<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see FlattenCountryEntity}: seeds a `countries` row of the
 * flattened-attribute (`on()`) Doctrine fixture (bundle ADR 0085) — the second hop
 * the book's multi-hop `on('author.country')` walks to.
 *
 * @extends PersistentObjectFactory<FlattenCountryEntity>
 */
final class FlattenCountryEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return FlattenCountryEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->country(),
        ];
    }
}
