<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see HolderEntity} (the include-safeguards `roots`/`caps`).
 *
 * @extends PersistentObjectFactory<HolderEntity>
 */
final class HolderEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return HolderEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => self::faker()->unique()->uuid(),
            'label' => self::faker()->word(),
            'kind' => 'root',
        ];
    }
}
