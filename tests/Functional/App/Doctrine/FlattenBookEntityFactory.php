<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see FlattenBookEntity}: seeds a `books` row of the
 * flattened-attribute (`on()`) Doctrine fixture (bundle ADR 0085). The `author` and
 * `publisher` associations are passed by the seeding trait once the related rows
 * exist.
 *
 * @extends PersistentObjectFactory<FlattenBookEntity>
 */
final class FlattenBookEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return FlattenBookEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'title' => self::faker()->sentence(3),
        ];
    }
}
