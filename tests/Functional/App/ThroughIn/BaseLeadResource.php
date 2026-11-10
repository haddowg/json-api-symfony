<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

/**
 * Leads filtered through their campaign and templates. The relationship leaves take
 * their value type from the related id: integers for advertisers, UUIDs for
 * campaigns, and `tpl-<id>` wire ids decoded for templates.
 */
abstract class BaseLeadResource extends AbstractResource
{
    public static string $type = 'wtiLeads';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            Str::make('status'),
            BelongsTo::make('campaign', 'wtiCampaigns'),
            BelongsToMany::make('templates', 'wtiTemplates'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereThroughIn::make('advertiser', 'campaign.advertiser'),
            WhereThroughIn::make('campaign'),
            WhereThroughIn::make('template', 'templates'),
            WhereThroughIn::make('advertiserName', 'campaign.advertiser.name'),
            WhereThroughIn::make('campaignName', 'campaign.name')->acceptsList(delimited: false),
            WhereThrough::make('advertiserIs', 'campaign.advertiser'),
            WhereThrough::make('usesTemplate', 'templates'),
            WhereAll::make(
                'openForAdvertiser',
                WhereThroughIn::make('openAdvertiser', 'campaign.advertiser'),
                Where::make('status')->fixed('open'),
            ),
            WhereAny::make(
                'advertiserOrTemplateName',
                WhereThroughIn::make('anyAdvertiserName', 'campaign.advertiser.name'),
                WhereThroughIn::make('anyTemplateName', 'templates.name'),
            ),
            FilterFamily::make('via', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => $suffix === 'advertiser'
                ? WhereThroughIn::make('via.advertiser', 'campaign.advertiser')->build()
                : null)
                ->acceptsList(),
        ];
    }
}
