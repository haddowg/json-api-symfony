<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Shape;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;

/**
 * The composite-attribute witness declaration, shared by both providers: an
 * {@see Obj} `address` (typed object in one value, per-child constraints), a
 * discriminated {@see OneOf} `block`, and a free-form {@see ArrayHash} `contact`
 * carrying a {@see Shape} composite-schema constraint (a discriminated `oneOf`
 * of an email and a phone shape, raw member schemas). The Obj/OneOf children
 * exercise the validator bridge's translated cascade
 * (`/data/attributes/address/city`, `/data/attributes/block/level`); the Shape
 * exercises the core opis {@see \haddowg\JsonApi\Validation\SchemaValueValidator}
 * value-validation pass (`/data/attributes/contact/...`). All three store as a
 * single array — the shape a `json` column round-trips.
 */
abstract class BaseCompositeWidgetResource extends AbstractResource
{
    public static string $type = 'composites';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            Obj::make('address')->nullable()->fields(
                Str::make('street')->required(),
                Str::make('city')->required(),
                Str::make('postcode')->required()->maxLength(10),
            ),
            OneOf::make('block')->nullable()->discriminator('kind')
                ->variant('heading', Str::make('text')->required(), Integer::make('level')->min(1)->max(6))
                ->variant('image', Url::make('src')->required(), Str::make('alt')),
            ArrayHash::make('contact')->nullable()->constrain(
                Shape::oneOf(
                    Schema::ofType('object')
                        ->withProperties([
                            'kind' => Schema::ofType('string')->withConst('email'),
                            'address' => Schema::ofType('string')->withFormat('email'),
                        ])
                        ->withRequired(['kind', 'address']),
                    Schema::ofType('object')
                        ->withProperties([
                            'kind' => Schema::ofType('string')->withConst('phone'),
                            'number' => Schema::ofType('string'),
                        ])
                        ->withRequired(['kind', 'number']),
                )->discriminator('kind'),
            ),
        ];
    }
}
