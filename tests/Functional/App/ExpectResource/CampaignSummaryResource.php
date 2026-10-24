<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Campaign::class, readOnly: true)]
final class CampaignSummaryResource extends AbstractResource
{
    public static string $type = 'campaign-summaries';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
