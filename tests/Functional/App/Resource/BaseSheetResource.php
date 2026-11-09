<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The open-object witness, shared by both providers: `rows` is an inline spreadsheet
 * import, each row an {@see Obj} requiring `Key` and taking any other header as a
 * bounded string; `labels` an open attribute with a member minimum; `settings` a closed
 * object nesting an open one; and `metrics` an object whose members are read-only.
 * `imported` and `preferences` take their writes through a `fillUsing()` hook that
 * stores nothing, yet are validated against their declared shape all the same.
 * Every object stores as a single array, the shape a `json` column round-trips.
 */
abstract class BaseSheetResource extends AbstractResource
{
    public static string $type = 'sheets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            ArrayList::make('rows')->nullable()->maxItems(1000)->of(
                Obj::make('row')
                    ->fields(Str::make('Key')->required())
                    ->additionalProperties(Str::make('*')->maxLength(20))
                    ->maxProperties(4),
            ),
            Obj::make('labels')->nullable()->additionalProperties(Str::make('*')->nullable()->maxLength(10))->minProperties(1),
            Obj::make('settings')->nullable()->fields(
                Str::make('theme'),
                Obj::make('limits')->additionalProperties(Integer::make('*')->min(0)),
            ),
            Obj::make('metrics')->nullable()->additionalProperties(Integer::make('*')->readOnly()),
            ArrayList::make('imported')->writeOnly()->nullable()->maxItems(1000)
                ->of(Obj::make('row')->additionalProperties(Str::make('cell')->maxLength(65535))->minProperties(1)->maxProperties(200))
                ->fillUsing(static fn(mixed $sheet): mixed => $sheet),
            Obj::make('preferences')->writeOnly()->nullable()
                ->fields(Str::make('theme')->maxLength(10), Obj::make('limits')->additionalProperties(Integer::make('*')->min(0))->maxProperties(2))
                ->fillUsing(static fn(mixed $sheet): mixed => $sheet),
        ];
    }
}
