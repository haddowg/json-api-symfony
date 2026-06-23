<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A board whose polymorphic `pinned` relation lists a candidate
 * ({@see CatchAllItemResource}, type `catch-all-items`) that does NOT override
 * `getType()` — the configuration guard A5
 * ({@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer}) must reject at
 * `cache:warmup`.
 */
final class UnsafePolyBoardResource extends AbstractResource
{
    public static string $type = 'poly-boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            MorphTo::make('pinned', ['discriminating-items', 'catch-all-items']),
        ];
    }
}
