<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\KeySelection;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PanelEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\Panel;

/**
 * The field-selection witness, shared by both providers: `values` is an open object
 * whose keys a pattern makes selectable, `groups` holds repeating groups selected whole,
 * `summary` is computed by a hook that records the keys it was asked for, `layout` is a
 * `Map` read by a hook typed on the entity or the plain model, and `settings` is a closed
 * object whose keys can't be selected.
 */
abstract class BasePanelResource extends AbstractResource
{
    public static string $type = 'panels';

    /**
     * The `$only` each `summary` hook call received, in call order.
     *
     * @var list<list<string>|null>
     */
    public static array $summaryRequests = [];

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            Obj::make('values')->nullable()
                ->additionalProperties(Str::make('*')->nullable())
                ->selectableKeys(pattern: '[a-z][a-z0-9_]*'),
            Obj::make('groups')->nullable()
                ->additionalProperties(ArrayList::make('*')->of(Obj::make('row')->fields(Integer::make('a'))))
                ->selectableKeys(pattern: '[a-z]+'),
            Obj::make('summary')
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(static fn(string $key, JsonApiRequestInterface $request): bool => \in_array($key, ['count', 'width'], true))
                ->computedUsing(static function (mixed $panel, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array {
                    self::$summaryRequests[] = $only?->keys;

                    return ['count' => 2, 'width' => 40];
                }),
            Map::make('layout')->nullable()->readOnly()
                ->fields(Str::make('region'), Str::make('theme'))
                ->selectableKeys(pattern: '[a-z]+')
                ->extractUsing(static function (PanelEntity|Panel $panel, JsonApiRequestInterface $request, string $name, ?KeySelection $only): ?array {
                    $region = $panel->values['region'] ?? null;
                    if (!\is_string($region)) {
                        return null;
                    }
                    $layout = ['region' => $region, 'theme' => 'grid'];

                    return $only === null ? $layout : \array_intersect_key($layout, \array_flip($only->keys));
                }),
            Obj::make('settings')->nullable()->fields(Str::make('theme')),
            BelongsTo::make('curator', 'curators')->nullable(),
        ];
    }
}
