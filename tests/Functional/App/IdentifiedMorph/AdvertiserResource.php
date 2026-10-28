<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
class AdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    /**
     * A `MorphTo` resolves its target's serializer by the type the target reports.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof IdentifiedAgency ? 'agencies' : static::$type;
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('region', 'regions'),
        ];
    }
}
