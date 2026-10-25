<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidget;

/**
 * In-memory providers for the per-server registry suites: `notes` and `labels`, which
 * no storage maps, and the in-memory `segmentWidgets`.
 */
final class NamedServerProviders
{
    public static function notes(): InMemoryDataProvider
    {
        $author = new Note(2, 'author');
        $note = new Note(1, 'note', $author, $author);

        return new InMemoryDataProvider('notes', ['1' => $note, '2' => $author], static function (object $item): string {
            \assert($item instanceof Note);

            return (string) $item->id;
        });
    }

    public static function labels(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('labels', ['1' => new Note(1, 'label')], static function (object $item): string {
            \assert($item instanceof Note);

            return (string) $item->id;
        });
    }

    public static function segmentWidgets(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('segmentWidgets', ['1' => new HeaderWidget(1, 'one')], static function (object $item): string {
            \assert($item instanceof HeaderWidget);

            return (string) $item->id;
        });
    }
}
