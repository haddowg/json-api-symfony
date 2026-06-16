<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\GadgetHydrator;
use haddowg\JsonApiBundle\Tests\Functional\App\GadgetSerializer;

/**
 * The custom serializer/hydrator witness (ADR 0023): the `gadget` type overrides
 * both its serializer and hydrator via the attribute, so the generic engine drives
 * its reads/writes through {@see GadgetSerializer} / {@see GadgetHydrator}. The
 * declared fields are inert (the overrides own the I/O); the resource is still the
 * registration anchor (type, routes).
 */
#[AsJsonApiResource(serializer: GadgetSerializer::class, hydrator: GadgetHydrator::class)]
final class GadgetResource extends AbstractResource
{
    public static string $type = 'gadget';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
