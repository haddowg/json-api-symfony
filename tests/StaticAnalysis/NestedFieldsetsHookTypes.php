<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\StaticAnalysis;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PanelEntity;

/**
 * Never executed. The nested fieldset hooks typed on a Doctrine entity: a key predicate, and read and serialize hooks
 * taking the selected keys as a fourth argument, a list's holding the keys of every item. phpstan-hooks.neon analyses it with
 * PHPStan's default settings, as an application would.
 */
final class NestedFieldsetsHookTypes
{
    /**
     * @return list<FieldBuilderInterface>
     */
    public static function declarations(): array
    {
        return [
            Obj::make('values')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(static fn(string $key, JsonApiRequestInterface $request): bool => $key !== 'internal', '[a-z]+')
                ->extractUsing(static fn(PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): ?array => $fieldset === null ? $panel->values : \array_intersect_key($panel->values ?? [], \array_flip($fieldset->keys))),
            Obj::make('summary')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->computedUsing(static fn(?PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset === null ? [] : $fieldset->keys),
            Map::make('totals')->fields(Integer::make('views'))
                ->nestedFieldset(static fn(string $key): bool => $key === 'views')
                ->extractUsing(static fn(PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => ['views' => \count($panel->values ?? [])]),
            ArrayList::make('rows')
                ->of(Obj::make('row')->fields(Str::make('label'))->nestedFieldset(pattern: '[a-z]+'))
                ->extractUsing(static fn(PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): ?array => $fieldset?->items() === null ? $panel->rows : \array_map(static fn(mixed $row): mixed => \is_array($row) ? \array_intersect_key($row, \array_flip($fieldset->items()->keys)) : $row, $panel->rows ?? [])),
            ArrayList::make('archive')
                ->of(Obj::make('row')->fields(Str::make('label'))->nestedFieldset(pattern: '[a-z]+'))
                ->computedUsing(static fn(?PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset === null ? [] : $fieldset->paths()),
            Obj::make('address')
                ->fields(Str::make('postcode'), Obj::make('geo')->fields(Integer::make('lat'))->nestedFieldset(pattern: 'lat'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->serializeUsing(static fn(PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): ?array => $fieldset === null ? $panel->address : ['geo' => $fieldset->nested('geo')?->keys]),
            ArrayList::make('cells')
                ->of(Obj::make('cell')->fields(Str::make('v'))->nestedFieldset(pattern: 'v'))
                ->serializeUsing(static fn(PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): ?array => $fieldset?->items() === null ? $panel->rows : $fieldset->items()->paths()),
        ];
    }
}
