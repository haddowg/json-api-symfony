<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fp-boards`, whose to-manys cover each way a rendered relationship is paginated:
 * `cards` two a page and countable, `featured` the same cards rendered with
 * `withData()`, `labels` a many-to-many two a page, `allCards` declared
 * `withoutPagination()`, and `pocketCards`, which exposes no endpoint.
 */
abstract class FpBoardResource extends AbstractResource
{
    public static string $type = 'fp-boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('cards', 'fp-cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))->countable(),
            HasMany::make('featured', 'fp-cards')->storedAs('cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))->withData(),
            BelongsToMany::make('labels', 'fp-labels')->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            HasMany::make('allCards', 'fp-cards')->storedAs('cards')->withoutPagination(),
            HasMany::make('pocketCards', 'fp-cards')->storedAs('cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->withoutRelatedEndpoint()->withoutRelationshipEndpoint(),
        ];
    }
}
