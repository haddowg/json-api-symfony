<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereIn;

/**
 * Advertisers, scoped so the hidden one exists for no caller. Its campaigns are read
 * once without the campaigns' filter default and once with it. The collection defaults to
 * advertisers with an early campaign, a season standing for the campaign names it covers.
 */
abstract class BaseThrAdvertiserResource extends AbstractResource
{
    public static string $type = 'thrAdvertisers';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            HasMany::make('campaigns', 'thrCampaigns')->withoutFilterDefaults('advertiserName'),
            HasMany::make('defaultCampaigns', 'thrCampaigns')->storedAs('campaigns'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIn::make('season', 'name')->through('campaigns')->acceptsList()->default('early')->deserializeUsing(ThrGroups::campaignNames(...)),
        ];
    }
}
