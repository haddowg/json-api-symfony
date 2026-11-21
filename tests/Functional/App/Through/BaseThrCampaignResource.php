<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * Campaigns keyed by UUID. Their start date is served as `startsOn`, and the collection
 * defaults to Acme's campaigns through the advertiser.
 */
abstract class BaseThrCampaignResource extends AbstractResource
{
    public static string $type = 'thrCampaigns';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('name')->sortable(),
            Integer::make('budget'),
            Date::make('startsOn')->storedAs('startDate'),
            DateTime::make('archivedAt')->nullable(),
            BelongsTo::make('advertiser', 'thrAdvertisers')->nullable(),
            HasMany::make('leads', 'thrLeads'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('advertiserName', 'name')->through('advertiser')->default('Acme'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('advertiserName', 'name')->through('advertiser'),
        ];
    }
}
