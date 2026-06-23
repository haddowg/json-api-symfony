<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A polymorphic member resource that does NOT override
 * {@see AbstractResource::getType()}: it returns its static `$type` for EVERY object,
 * so core's {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()}
 * (which matches a member's own `getType()` against each declared type) would let it
 * silently claim — and mis-serialize — members of its sibling types. Guard A5
 * ({@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer}) must reject this
 * candidate at `cache:warmup`.
 */
final class CatchAllItemResource extends AbstractResource
{
    public static string $type = 'catch-all-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }

    // Deliberately no getType() override — the fault the guard catches.
}
