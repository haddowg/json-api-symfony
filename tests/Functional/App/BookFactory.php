<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `book` pair for the uriType witness. The provider
 * is keyed on the JSON:API type `book` (not the `books` URI segment) — the SPI
 * resolves by type; the segment is only a URL concern.
 */
final class BookFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        $books = ['b1' => new Book('b1', 'JSON:API at Work')];

        return new InMemoryDataProvider('book', $books, static function (object $item): string {
            \assert($item instanceof Book);

            return $item->id;
        });
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('book', $provider->store(), static fn(): Book => new Book());
    }
}
