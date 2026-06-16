<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see NodeEntity}: the Doctrine include-safeguards suite
 * seeds the circular `nodes` chain through this with explicit attributes.
 *
 * @extends PersistentObjectFactory<NodeEntity>
 */
final class NodeEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return NodeEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'id' => self::faker()->unique()->uuid(),
            'label' => self::faker()->word(),
        ];
    }
}
