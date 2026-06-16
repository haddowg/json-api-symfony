<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see TagEntity} (the include-safeguards `tags`).
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
        return [
            'id' => self::faker()->unique()->uuid(),
            'name' => self::faker()->word(),
        ];
    }
}
