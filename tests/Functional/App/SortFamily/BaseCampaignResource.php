<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * Campaigns sortable by placeholder value through the `value` family and by field
 * through the `field` family, beside the fixed `id` and `name` keys. Pages default to
 * a cursor of two; `page[number]` selects page-number paging.
 */
abstract class BaseCampaignResource extends AbstractResource
{
    public const array PLACEHOLDERS = ['cap'];

    public static string $type = 'campaigns';

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            Str::make('name')->sortable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortFamily::make('value', static fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface => \in_array($suffix, self::PLACEHOLDERS, true)
                ? new PlaceholderValueSort('value.' . $suffix, $suffix)
                : null)
                ->withSuffixPattern('[a-z_]+'),
            SortFamily::make('field', static fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface => $suffix === 'name'
                ? SortByField::make('field.name', 'name')
                : null),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return MultiPaginator::make(
            PagePaginator::make()->withDefaultPerPage(2),
            CursorPaginator::make()->withDefaultSize(2),
        )->default('cursor');
    }
}
