<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * Leads filtered and sorted through their campaign, its advertiser and their templates.
 * The relation to the campaign is stored as `campaign` but named `owningCampaign`, so
 * every path names it by its API name.
 */
abstract class BaseThrLeadResource extends AbstractResource
{
    public static string $type = 'thrLeads';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name')->sortable(),
            BelongsTo::make('owningCampaign', 'thrCampaigns')->storedAs('campaign')->nullable(),
            BelongsToMany::make('templates', 'thrTemplates'),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return MultiPaginator::make(PagePaginator::make()->withDefaultPerPage(10), CursorPaginator::make()->withDefaultSize(10));
    }

    public function filters(): array
    {
        return [
            Contains::make('campaignName', 'name')->through('owningCampaign'),
            DateRange::make('campaignDates', 'startsOn')->through('owningCampaign'),
            Range::make('budget')->through('owningCampaign'),
            Where::make('advertiserName', 'name')->through('owningCampaign.advertiser'),
            WhereIdIn::make('campaign')->through('owningCampaign'),
            WhereIdNotIn::make('notCampaign')->through('owningCampaign'),
            WhereIdIn::make('advertiser')->through('owningCampaign.advertiser'),
            WhereIdIn::make('template')->through('templates'),
            // Keyed by a relation, an id filter compares that relation's ids with no path.
            WhereIdIn::make('templates'),
            WhereIdNotIn::make('owningCampaign'),
            WhereIn::make('templateName', 'name')->through('templates')->acceptsList(),
            WhereNotIn::make('notTemplateName', 'name')->through('templates'),
            // A requested group stands for the template names it covers.
            WhereIn::make('templateGroup', 'name')->through('templates')->enum(ThrTemplateGroup::class)->acceptsList()->deserializeUsing(ThrGroups::templateNames(...)),
            WhereNotIn::make('notTemplateGroup', 'name')->through('templates')->enum(ThrTemplateGroup::class)->acceptsList()->deserializeUsing(ThrGroups::templateNames(...)),
            WhereNull::make('unarchived', 'archivedAt')->through('owningCampaign'),
            WhereSet::make('archived', 'archivedAt')->through('owningCampaign'),
            WhereHas::make('advertised', 'advertiser')->through('owningCampaign'),
            WhereDoesntHave::make('unadvertised', 'advertiser')->through('owningCampaign'),
            WhereDoesntHave::make('noCampaign', 'campaign'),
            WhereAny::make(
                'search',
                Where::make('searchAdvertiser', 'name')->through('owningCampaign.advertiser'),
                Where::make('searchTemplate', 'name')->through('templates'),
            ),
            FilterFamily::make('via', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => $suffix === 'advertiser'
                ? Where::make('via.advertiser', 'name')->through('owningCampaign.advertiser')->build()
                : null),
            // The deprecated traversal it replaces, kept to show both behave the same.
            WhereThrough::make('legacyAdvertiserName', 'campaign.advertiser.name'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('campaignStart', 'startsOn')->through('owningCampaign'),
            SortByField::make('advertiserName', 'name')->through('owningCampaign.advertiser'),
            SortFamily::make('by', static fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface => $suffix === 'budget'
                ? SortByField::make('by.budget', 'budget')->through('owningCampaign')
                : null),
        ];
    }
}
