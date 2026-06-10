<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see CommentEntity}: the Doctrine functional suite seeds
 * the related `comments` rows through this with explicit attributes (id, body
 * and the owning `article`) for the canonical conformance fixtures.
 *
 * @extends PersistentObjectFactory<CommentEntity>
 */
final class CommentEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return CommentEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => self::faker()->unique()->uuid(),
            'body' => self::faker()->sentence(),
        ];
    }
}
