<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Agency::class, readOnly: true)]
final class AgencyResource extends AbstractResource
{
    public static string $type = 'agencies';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('region'),
        ];
    }
}
