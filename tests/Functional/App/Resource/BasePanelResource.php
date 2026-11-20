<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\KeySelection;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PanelEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\BlockType;
use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\Panel;

/**
 * The field-selection witness, shared by both providers: `values` is an open object
 * whose keys a pattern makes selectable, `groups` holds repeating groups selected whole,
 * `summary` is computed by a hook that records the keys it was asked for, `layout` is a
 * `Map` read by a hook typed on the entity or the plain model, `settings` is a closed
 * object whose keys can't be selected, and `address` is a closed object whose `geo` is
 * selectable in turn while its `history` list is not. `rows` and `archive` are lists whose
 * items' keys are selected through `*`, each row holding an object and a list selectable in
 * turn and open to other members, `tags` is a list of strings, `blocks` is a list of a
 * union bound to an enum whose text and image variants each declare their own selectable
 * keys and whose divider declares none, and `hero` is a union attribute whose image variant
 * declares them and whose video variant doesn't.
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

    private static function row(): Obj
    {
        return Obj::make('row')
            ->fields(
                Str::make('label'),
                Obj::make('size')->fields(Integer::make('w'), Integer::make('h'))->selectableKeys(pattern: '[a-z]'),
                ArrayList::make('cells')->of(Obj::make('cell')->fields(Str::make('v'))->selectableKeys(pattern: 'v')),
            )
            ->additionalProperties(Str::make('*')->nullable())
            ->selectableKeys(pattern: '[a-z]+')
            ->build();
    }

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
            Obj::make('address')->nullable()
                ->fields(
                    Str::make('street'),
                    Str::make('postcode'),
                    Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'), Str::make('accuracy'))->selectableKeys(pattern: '[a-z]+'),
                    ArrayList::make('history')->of(Obj::make('entry')->fields(Str::make('street'))),
                )
                ->selectableKeys(pattern: '[a-z]+'),
            ArrayList::make('rows')->nullable()->of(self::row()),
            ArrayList::make('archive')->nullable()->of(self::row()),
            ArrayList::make('tags')->nullable(),
            ArrayList::make('blocks')->nullable()->of(
                OneOf::make('block')->discriminator('type', BlockType::class)
                    ->variants(static fn(BlockType $type): Obj => match ($type) {
                        BlockType::Text => Obj::make('text')
                            ->fields(Str::make('title'), Str::make('body'), Obj::make('meta')->fields(Str::make('lang'), Str::make('tone'))->selectableKeys(pattern: 'lang|tone'))
                            ->selectableKeys(pattern: 'title|body|meta')
                            ->build(),
                        BlockType::Image => Obj::make('image')->fields(Str::make('title'), Str::make('url'))->selectableKeys(pattern: 'url')->build(),
                        BlockType::Divider => Obj::make('divider')->fields(Str::make('style'))->build(),
                    }),
            ),
            OneOf::make('hero')->nullable()->discriminator('kind')
                ->variant('image', Obj::make('image')->fields(Str::make('src'), Str::make('alt'))->selectableKeys(pattern: 'src|alt'))
                ->variant('video', Str::make('url')),
            BelongsTo::make('curator', 'curators')->nullable(),
        ];
    }
}
