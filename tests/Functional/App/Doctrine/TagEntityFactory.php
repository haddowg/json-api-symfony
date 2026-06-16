<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see TagEntity}: the Doctrine genericity-witness suite
 * seeds the `tags` rows through this with explicit attributes, mirroring
 * {@see AuthorEntityFactory}.
 *
 * @extends PersistentObjectFactory<TagEntity>
 */
final class TagEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return TagEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'name' => self::faker()->unique()->word(),
        ];
    }
}
