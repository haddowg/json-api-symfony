<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
class CpFleetResource extends AbstractResource
{
    public static string $type = 'cp-fleets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('ships', 'cp-ships'),
            BelongsTo::make('flagship', 'cp-ships'),
        ];
    }
}
