<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds empty in-memory providers (and matching persisters) for the metadata-only
 * `products` / `brands` / `regions` resources of the eager-load validation fixture.
 *
 * These resources never serve a real request — the suite only invokes the
 * {@see \haddowg\JsonApiBundle\Serializer\EagerLoadWarmer} against their metadata — but
 * they declare the full (read + write) operation set, so the servability warm-up guard
 * requires a provider and persister per type. The stores stay empty; the factory/identify
 * closures are never exercised.
 */
final class EagerValidationDataFactory
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
