<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see AuthorEntity}: the Doctrine functional suite seeds
 * the related `authors` rows through this with explicit attributes for the
 * canonical conformance fixtures.
 *
 * @extends PersistentObjectFactory<AuthorEntity>
 */
final class AuthorEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AuthorEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it by insertion order.
        return [
            'name' => self::faker()->name(),
        ];
    }
}
