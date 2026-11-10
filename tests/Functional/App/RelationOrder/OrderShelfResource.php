<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * `books` declares its own order, title descending, replacing the books' own oldest
 * first; `booksByYear` reads the same books and keeps the books' order. `items` is a
 * MorphToMany over books and magazines declared by its link rows' fields, which carry a
 * rank and a note, read in rank order. `closureItems` reads the same rows through an
 * identifiedBy() closure, so only core's in-memory query serves it.
 */
class OrderShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('books', 'books')
                ->withData()
                ->paginate(PagePaginator::make())
                ->defaultSort(new SortDirective(SortByField::make('title'), descending: true)),
            HasMany::make('booksByYear', 'books')
                ->storedAs('books')
                ->withData(),
            MorphToMany::make('items', ['books', 'magazines'])
                ->through(OrderShelfItem::class)
                ->identifiedByRows(owner: 'shelf', type: 'itemType', id: 'itemId')
                ->fields(
                    Integer::make('rank')->required(),
                    Str::make('note'),
                )
                ->withFilters(Where::make('minRank', 'pivot.rank', '>=')->deserializeUsing(self::integer(...)))
                ->paginate(PagePaginator::make())
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false)),
            MorphToMany::make('closureItems', ['books', 'magazines'])
                ->identifiedBy(static fn(mixed $shelf): array => $shelf instanceof OrderShelf
                    ? \array_values(\array_map(static fn(OrderShelfItem $row): array => [$row->itemType, $row->itemId, $row], $shelf->items->toArray()))
                    : [])
                ->fields(
                    Integer::make('rank')->required(),
                    Str::make('note'),
                )
                ->withFilters(Where::make('minRank', 'pivot.rank', '>=')->deserializeUsing(self::integer(...)))
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false))
                ->fillUsing(self::fillItems(...)),
        ];
    }

    /**
     * Adds and removes the link rows the linkage names; the bundle writes their pivot
     * fields from meta.pivot.
     *
     * @param array<string, mixed> $data
     */
    private static function fillItems(mixed $shelf, mixed $linkage, array $data): mixed
    {
        \assert($shelf instanceof OrderShelf && $linkage instanceof ToManyRelationship);
        $sent = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            $sent[$identifier->type . ':' . $identifier->id] = [$identifier->type, (int) $identifier->id];
        }

        $mode = $data['mode'] ?? Mode::Replace;
        foreach ($shelf->items->toArray() as $row) {
            $named = isset($sent[$row->key()]);
            if (($mode === Mode::Replace && !$named) || ($mode === Mode::Remove && $named)) {
                $shelf->items->removeElement($row);
            }
            unset($sent[$row->key()]);
        }

        if ($mode !== Mode::Remove) {
            foreach ($sent as [$type, $id]) {
                $shelf->items->add(new OrderShelfItem(shelf: $shelf, itemType: $type, itemId: $id));
            }
        }

        return $shelf;
    }

    private static function integer(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }
}
