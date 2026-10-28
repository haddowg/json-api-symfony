<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Campaigns whose `target` renders from the stored type and id columns.
 */
#[AsJsonApiResource(readOnly: true)]
class CampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphTo::make('target', ['advertisers', 'agencies'])
                ->identifiedBy(static fn(mixed $campaign): ?array => $campaign instanceof IdentifiedCampaign && $campaign->targetType !== null && $campaign->targetId !== null
                    ? [$campaign->targetType, $campaign->targetId]
                    : null)
                ->readOnly(),
        ];
    }
}
