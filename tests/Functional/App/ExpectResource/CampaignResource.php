<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Campaign::class, readOnly: true)]
final class CampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Integer::make('budget'),
        ];
    }
}
