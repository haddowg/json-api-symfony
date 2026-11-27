<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

/**
 * The type a fleet's polymorphic members render as, read off their class.
 */
trait TypedByClass
{
    public function getType(mixed $object): string
    {
        return match (true) {
            $object instanceof CpShip => 'cp-ships',
            $object instanceof CpCrew => 'cp-crews',
            default => static::$type,
        };
    }
}
