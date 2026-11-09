<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `submissions` provider, seeded with one submission so a PATCH has
 * a target, and a persister sharing its store.
 */
final class SubmissionFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'submissions',
            ['1' => new Submission('1', 'Seed')],
            static function (object $item): string {
                \assert($item instanceof Submission);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Submission);

                $item->id = $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('submissions', $provider->store(), static fn(): Submission => new Submission());
    }
}
