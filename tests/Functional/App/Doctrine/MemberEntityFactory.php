<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for {@see MemberEntity}: the multi-type-per-entity Doctrine suite
 * seeds the member rows through this with explicit attributes.
 *
 * @extends PersistentObjectFactory<MemberEntity>
 */
final class MemberEntityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return MemberEntity::class;
    }

    protected function defaults(): array
    {
        return [
            'displayName' => self::faker()->name(),
            'email' => self::faker()->email(),
            'secretNote' => self::faker()->sentence(),
        ];
    }
}
