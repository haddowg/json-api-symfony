<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Campaigns keyed by UUID, each run for one advertiser and holding its leads.
 */
abstract class BaseCampaignResource extends AbstractResource
{
    public static string $type = 'wtiCampaigns';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('name'),
            BelongsTo::make('advertiser', 'wtiAdvertisers'),
            HasMany::make('leads', 'wtiLeads'),
        ];
    }
}
