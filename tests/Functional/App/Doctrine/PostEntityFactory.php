<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see PostEntity}: the multi-type-per-entity Doctrine suite
 * seeds the post rows (with their author association) through this.
 *
 * @extends PersistentObjectFactory<PostEntity>
 */
final class PostEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return PostEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'title' => self::faker()->sentence(),
            'author' => null,
        ];
    }
}
