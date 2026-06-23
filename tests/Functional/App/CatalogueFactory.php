<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory provider behind the read-only `catalogues` type (E1). No
 * persister factory: the `readOnly` shorthand exposes no writes, so the type needs
 * only a provider to be servable.
 */
final class CatalogueFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('catalogues', [
            1 => new Catalogue(1, 'Singles'),
            2 => new Catalogue(2, 'Albums'),
        ]);
    }
}
