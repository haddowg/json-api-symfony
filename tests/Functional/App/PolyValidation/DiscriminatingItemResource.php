<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A polymorphic member resource that DOES override {@see AbstractResource::getType()}
 * — it discriminates a member by class, so it never silently claims a sibling's
 * objects. A candidate like this passes guard A5
 * ({@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer}). A safe polymorphic
 * relation lists only resources of this shape.
 */
final class DiscriminatingItemResource extends AbstractResource
{
    public static string $type = 'discriminating-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }

    public function getType(mixed $object): string
    {
        // Object-aware: discriminate the member by class (the trivial single-class
        // case here always returns its own type, but the OVERRIDE is what the guard
        // checks — an author writing this would branch on `instanceof`).
        return self::$type;
    }
}
