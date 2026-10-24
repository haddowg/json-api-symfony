<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * An empty provider for the security enforcement build fixtures, which only need to
 * pass the servability check; they never serve a request.
 */
final class GuardGadgetDataFactory
{
    public static function provider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('guard-gadgets', [], static fn(object $item): string => \spl_object_hash($item));
    }
}
