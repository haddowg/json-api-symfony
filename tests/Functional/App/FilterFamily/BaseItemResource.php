<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

/**
 * Items filterable by list and by family. `name` and `weight` take a delimited list,
 * `labels` an array-only one. The `attr` family resolves a column to a scalar filter,
 * the `any` family to a list filter, and the exact `attr.shade` sits beside `attr`.
 * `only` and the `one` family's `one.name` are singular filters on the same column.
 */
abstract class BaseItemResource extends AbstractResource
{
    public static string $type = 'ffItems';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('label'),
            Str::make('color'),
            Str::make('size'),
            Integer::make('weight'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIn::make('name')->acceptsList(),
            WhereIn::make('labels', 'label')->acceptsList(delimited: false),
            WhereIn::make('weight')->acceptsList()->integer(),
            Where::make('attr.shade', 'color'),
            Where::make('only', 'name')->singular(),
            FilterFamily::make('one', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => $suffix === 'name'
                ? Where::make('one.name', 'name')->singular()->build()
                : null),
            FilterFamily::make('attr', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => \in_array($suffix, ['color', 'size'], true)
                ? Where::make('attr.' . $suffix, $suffix)->build()
                : null)
                ->withSuffixPattern('[a-z]+'),
            FilterFamily::make('any', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => match ($suffix) {
                'color', 'size' => WhereIn::make('any.' . $suffix, $suffix)->acceptsList()->build(),
                'weight' => WhereIn::make('any.weight', 'weight')->acceptsList()->integer()->build(),
                default => null,
            })
                ->acceptsList(),
        ];
    }
}
