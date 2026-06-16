<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `tags` pair for the genericity witness: an
 * {@see InMemoryDataProvider} seeded with two unlinked tags and an
 * {@see InMemoryDataPersister} over the *same* store, so a created/updated/deleted
 * tag is immediately readable — the same wiring shape as
 * {@see WritableArticleFactory}, with no tag-specific engine code.
 */
final class WritableTagFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        $tags = [
            't1' => new Tag('t1', 'PHP'),
            't2' => new Tag('t2', 'Testing'),
        ];

        return new InMemoryDataProvider('tags', $tags, static function (object $item): string {
            \assert($item instanceof Tag);

            return $item->id;
        });
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('tags', $provider->store(), static fn(): Tag => new Tag());
    }
}
