<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fp-boards`, whose to-manys cover each way a rendered relationship is paginated:
 * `cards` two a page and countable, `featured` the same cards rendered with
 * `withData()`, `labels` a many-to-many two a page, `allCards` declared
 * `withoutPagination()`, `pocketCards`, which exposes no endpoint, and two polymorphic
 * sets two a page: `pins`, computed by a closure, and `tagged`, from stored pairs.
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
            HasMany::make('featured', 'fp-cards')->storedAs('cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))->withData()->notSparseField(),
            BelongsToMany::make('labels', 'fp-labels')->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            HasMany::make('allCards', 'fp-cards')->storedAs('cards')->withoutPagination(),
            // Every label then every card, a polymorphic set its own closure computes.
            MorphToMany::make('pins', ['fp-labels', 'fp-cards'])->readOnly()
                ->extractUsing(static fn(mixed $board): array => $board instanceof FpBoard ? [...$board->labels->toArray(), ...$board->cards->toArray()] : [])
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            MorphToMany::make('tagged', ['fp-labels', 'fp-cards'])->readOnly()
                ->identifiedBy(static fn(mixed $board): array => $board instanceof FpBoard ? $board->taggedPairs() : [])
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
            HasMany::make('pocketCards', 'fp-cards')->storedAs('cards')->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->withoutRelatedEndpoint()->withoutRelationshipEndpoint(),
        ];
    }
}
