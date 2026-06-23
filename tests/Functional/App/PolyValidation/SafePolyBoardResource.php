<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A board whose polymorphic `pinned` relation lists only candidates that DO override
 * `getType()` ({@see DiscriminatingItemResource} /
 * {@see AlsoDiscriminatingItemResource}) — a correct configuration that guard A5
 * ({@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer}) lets boot clean.
 */
final class SafePolyBoardResource extends AbstractResource
{
    public static string $type = 'poly-boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            MorphTo::make('pinned', ['discriminating-items', 'also-discriminating-items']),
        ];
    }
}
