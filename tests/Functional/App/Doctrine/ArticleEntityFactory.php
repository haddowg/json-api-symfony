<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see ArticleEntity}: the Doctrine functional suite seeds
 * the in-memory SQLite database through this (with explicit attributes for the
 * canonical conformance rows) instead of hand-persisting fixture objects.
 *
 * @extends PersistentObjectFactory<ArticleEntity>
 */
final class ArticleEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ArticleEntity::class;
    }

    protected function defaults(): array
    {
        // No `id`: the store-provided `AUTO` column assigns it on insert, so seeded
        // rows get sequential ints by insertion order.
        return [
            'title' => self::faker()->unique()->sentence(3),
            'body' => self::faker()->paragraph(),
            'category' => self::faker()->word(),
        ];
    }
}
