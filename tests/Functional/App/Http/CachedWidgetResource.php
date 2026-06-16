<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Http;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The declarative HTTP cache witness (bundle ADR 0054, gap G7): a resource-level
 * `cacheHeaders` (a one-minute public lifetime that varies on `Accept`) plus a
 * per-operation `collection` override (a shorter 30s lifetime) — proving the
 * resource-level directives apply to a single read and the per-read-shape override
 * layers over them on the collection.
 */
#[AsJsonApiResource(
    cacheHeaders: [
        'max_age' => 60,
        'public' => true,
        'vary' => ['Accept'],
        'operations' => [
            'collection' => ['max_age' => 30],
        ],
    ],
)]
final class CachedWidgetResource extends AbstractResource
{
    public static string $type = 'cachedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
