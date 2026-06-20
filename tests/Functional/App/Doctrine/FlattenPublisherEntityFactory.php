<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see FlattenPublisherEntity}: seeds the `publishers` row of
 * the flattened-attribute (`on()`) Doctrine fixture (bundle ADR 0085).
 *
 * @extends PersistentObjectFactory<FlattenPublisherEntity>
 */
final class FlattenPublisherEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return FlattenPublisherEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->company(),
        ];
    }
}
