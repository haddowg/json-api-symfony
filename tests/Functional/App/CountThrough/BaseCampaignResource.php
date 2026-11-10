<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CountThrough;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine\CampaignEntity;

/**
 * Campaigns of one brand, counting their leads through an inverse foreign key and
 * their shortlist through a join table. Each relation scopes a `leadBrand` filter to
 * its related endpoint and its counts.
 */
abstract class BaseCampaignResource extends AbstractResource
{
    public static string $type = 'ctCampaigns';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            BelongsTo::make('brand', 'ctBrands'),
            HasMany::make('leads', 'ctLeads')
                ->paginate(PagePaginator::make())
                ->countable()
                ->withFilters(WhereThrough::make('leadBrand', 'brand.name')),
            HasMany::make('shortlist', 'ctLeads')
                ->paginate(PagePaginator::make())
                ->countable()
                ->withFilters(WhereThrough::make('leadBrand', 'brand.name')),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof CampaignEntity ? static::$type : '';
    }
}
