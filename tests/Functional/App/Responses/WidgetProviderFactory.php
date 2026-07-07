<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Responses;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Seeds the in-memory `widgets` provider (and its persister, required for a
 * write-exposing type by the servable-resource warm-up) for the
 * {@see ResponseDeclarationTestKernel}. Widget `1` is "completed" (its fetch-one
 * redirects; see {@see WidgetResource::completionLocation()}); widget `2` is not.
 */
final class WidgetProviderFactory
{
    public static function widgets(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('widgets', [
            '1' => new Widget('1', 'Alpha'),
            '2' => new Widget('2', 'Beta'),
        ]);
    }

    public static function widgetsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('widgets', $provider->store(), static fn(): Widget => new Widget('', ''));
    }
}
