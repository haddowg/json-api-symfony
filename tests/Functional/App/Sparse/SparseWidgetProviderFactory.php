<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Sparse;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Seeds the in-memory `sparseWidgets` provider with a single widget carrying both a
 * cheap `name` and an `expensiveScore` — the seed the sparse-by-default witness reads
 * with and without a `fields[sparseWidgets]` opt-in.
 */
final class SparseWidgetProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('sparseWidgets', [
            '1' => new SparseWidget('1', 'Gadget', 99),
        ]);
    }

    /**
     * A persister sharing the provider's store, so the (write-exposing by default)
     * type passes the kernel's warm-up guard, which requires a persister per type.
     */
    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('sparseWidgets', $provider->store(), static fn(): SparseWidget => new SparseWidget());
    }
}
