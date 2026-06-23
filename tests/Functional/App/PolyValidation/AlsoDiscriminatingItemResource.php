<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A second discriminating polymorphic member resource (overrides
 * {@see AbstractResource::getType()}), so the SAFE board's polymorphic relation lists
 * two candidates that BOTH discriminate — proving guard A5 lets a correct
 * polymorphic configuration boot clean.
 */
final class AlsoDiscriminatingItemResource extends AbstractResource
{
    public static string $type = 'also-discriminating-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }

    public function getType(mixed $object): string
    {
        return self::$type;
    }
}
