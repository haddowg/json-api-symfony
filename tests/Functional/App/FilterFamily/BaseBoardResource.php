<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine\BoardEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine\ItemEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine\PinEntity;

/**
 * Boards of items. The `items` relation adds an exact `any` filter, which the items'
 * own `any` family leaves alone because a family never answers its bare prefix.
 *
 * `pinned` is a pivot `belongsToMany` whose pivot filters are declared both exactly,
 * `rank` and `pinnedAfter`, and through the `pin` family as `pin.rank` and `pin.after`
 * over the same columns.
 */
abstract class BaseBoardResource extends AbstractResource
{
    public static string $type = 'ffBoards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('items', 'ffItems')
                ->withFilters(WhereIn::make('any', 'color')->acceptsList()),
            BelongsToMany::make('pinned', 'ffItems')
                ->through(PinEntity::class)
                ->fields(
                    Integer::make('rank')->build(),
                    DateTime::make('pinnedAt')->build(),
                )
                ->withFilters(
                    Where::make('rank', 'pivot.rank'),
                    Where::make('pinnedAfter', 'pivot.pinnedAt', '>'),
                    FilterFamily::make('pin', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => match ($suffix) {
                        'rank' => Where::make('pin.rank', 'pivot.rank')->build(),
                        'after' => Where::make('pin.after', 'pivot.pinnedAt', '>')->build(),
                        default => null,
                    }),
                )
                ->extractUsing(static fn(mixed $board): array => match (true) {
                    $board instanceof Board => $board->pinned,
                    $board instanceof BoardEntity => \array_values(\array_filter(
                        \array_map(static fn(PinEntity $pin): ?ItemEntity => $pin->item, $board->pins->toArray()),
                    )),
                    default => [],
                }),
        ];
    }
}
