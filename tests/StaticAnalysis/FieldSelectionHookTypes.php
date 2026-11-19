<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\StaticAnalysis;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\KeySelection;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PanelEntity;

/**
 * Never executed. The selectable-key hooks typed on a Doctrine entity: a key predicate, and read hooks
 * taking the selected keys as a fourth argument. phpstan-hooks.neon analyses it with
 * PHPStan's default settings, as an application would.
 */
final class FieldSelectionHookTypes
{
    /**
     * @return list<FieldBuilderInterface>
     */
    public static function declarations(): array
    {
        return [
            Obj::make('values')
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(static fn(string $key, JsonApiRequestInterface $request): bool => $key !== 'internal', '[a-z]+')
                ->extractUsing(static fn(PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?KeySelection $only): ?array => $only === null ? $panel->values : \array_intersect_key($panel->values ?? [], \array_flip($only->keys))),
            Obj::make('summary')
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(pattern: '[a-z]+')
                ->computedUsing(static fn(?PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array => $only === null ? [] : $only->keys),
            Map::make('totals')->fields(Integer::make('views'))
                ->selectableKeys(static fn(string $key): bool => $key === 'views')
                ->extractUsing(static fn(PanelEntity $panel, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array => ['views' => \count($panel->values ?? [])]),
        ];
    }
}
