<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * A lead declaring one filter or sort that cannot resolve, chosen by {@see self::$declaration},
 * so the warm-up refuses it.
 */
abstract class BaseBrokenThrResource extends AbstractResource
{
    public static string $type = 'thrBroken';

    public static string $declaration = 'storageName';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            BelongsTo::make('owningCampaign', 'thrCampaigns')->storedAs('campaign'),
            BelongsToMany::make('templates', 'thrTemplates'),
        ];
    }

    public function filters(): array
    {
        return match (self::$declaration) {
            'storageName' => [Where::make('campaignName', 'name')->through('campaign')],
            'noField' => [Where::make('campaignCost', 'cost')->through('owningCampaign')],
            'relationLeaf' => [Where::make('advertiser')->through('owningCampaign')],
            'ambiguousId' => [WhereIdIn::make('templates', 'legacyKey')],
            default => [],
        };
    }

    public function sorts(): array
    {
        return self::$declaration === 'toManySort' ? [SortByField::make('templateName', 'name')->through('templates')] : [];
    }
}
