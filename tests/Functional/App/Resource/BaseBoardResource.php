<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The fields of a type that renders through a serializer override: the Resource declares
 * which keys a request may select, and the override renders each attribute whole.
 */
abstract class BaseBoardResource extends AbstractResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Obj::make('values')->nullable()
                ->additionalProperties(Str::make('*')->nullable())
                ->nestedFieldset(pattern: '[a-z][a-z0-9_]*'),
            Obj::make('address')->nullable()
                ->fields(
                    Str::make('street'),
                    Str::make('postcode'),
                    Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'), Str::make('accuracy'))->nestedFieldset(pattern: '[a-z]+'),
                    ArrayList::make('history')->of(Obj::make('entry')->fields(Str::make('street'))),
                )
                ->nestedFieldset(pattern: '[a-z]+'),
            ArrayList::make('rows')->nullable()->of(
                Obj::make('row')
                    ->fields(Str::make('label'), Obj::make('size')->fields(Integer::make('w'), Integer::make('h'))->nestedFieldset(pattern: '[a-z]'))
                    ->additionalProperties(Str::make('*')->nullable())
                    ->nestedFieldset(pattern: '[a-z]+'),
            ),
        ];
    }
}
