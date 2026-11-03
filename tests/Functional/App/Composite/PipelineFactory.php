<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `pipelines` provider, seeded with one pipeline so a PATCH
 * has a target, and a persister sharing its store.
 */
final class PipelineFactory
{
    public const array SEED_STEPS = [['operation' => 'trim']];
    public const array SEED_STOPS = [['city' => 'London', 'code' => 'LON', 'nights' => 2]];

    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'pipelines',
            ['1' => new Pipeline('1', 'Seed', self::SEED_STEPS, self::SEED_STOPS)],
            static function (object $item): string {
                \assert($item instanceof Pipeline);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Pipeline);

                $item->id = $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('pipelines', $provider->store(), static fn(): Pipeline => new Pipeline());
    }
}
