<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds empty in-memory providers (and matching persisters) for the metadata-only
 * resources of the polymorphic-discrimination warm-up guard fixture (guard A5).
 *
 * The resources never serve a real request — the suite only invokes the
 * {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer} against their metadata —
 * but they declare the full operation set, so the servability guard requires a provider
 * and persister per type. The stores stay empty.
 */
final class PolyValidationDataFactory
{
    public static function provider(string $type): InMemoryDataProvider
    {
        return new InMemoryDataProvider($type, [], static fn(object $item): string => \spl_object_hash($item));
    }

    public static function persister(string $type, InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister($type, $provider->store(), static fn(): object => new \stdClass());
    }
}
