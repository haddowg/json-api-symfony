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
        $widgets = [
            '1' => new Widget(1, 'First widget'),
            '2' => new Widget(2, 'Second widget'),
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
