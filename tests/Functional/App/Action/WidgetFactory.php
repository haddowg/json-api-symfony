<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `actionWidgets` pair: an
 * {@see InMemoryDataProvider} seeded with two widgets and an
 * {@see InMemoryDataPersister} over the *same* store, so an action's side-effect is
 * immediately readable through a follow-up fetch (mirroring
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\WritableArticleFactory}).
 */
final class WidgetFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        // Widget 1's `related` to-one points at widget 2, so `?include=related` renders
        // widget 2 as an included member — the asLink-link-on-an-included-resource
        // witness (bundle ADR 0091). Widget 2's `related` is null (an empty to-one).
        $second = new Widget(2, 'Second widget');
        $widgets = [
            '1' => new Widget(1, 'First widget', related: $second),
            '2' => $second,
        ];

        return new InMemoryDataProvider(
            'actionWidgets',
            $widgets,
            static function (object $item): string {
                \assert($item instanceof Widget);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Widget);

                $item->id = (int) $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('actionWidgets', $provider->store(), static fn(): Widget => new Widget());
    }
}
