<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `checklists` provider, seeded with one checklist so a PATCH
 * has a target, and a persister sharing its store.
 */
final class ChecklistFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'checklists',
            ['1' => new Checklist('1', true, 3, 'Seed', ['a'], ['k' => 'v'])],
            static function (object $item): string {
                \assert($item instanceof Checklist);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Checklist);

                $item->id = $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('checklists', $provider->store(), static fn(): Checklist => new Checklist());
    }
}
