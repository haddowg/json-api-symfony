<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Shape;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Release;

/**
 * The `releases` resource type — the composite-attribute showcase (core ADRs
 * 0118–0121), exercising all three composite kinds on one resource:
 *
 * - `format` is a discriminated {@see OneOf}: its `medium` key selects the
 *   vinyl / cd / digital variant, each variant's children validating (and
 *   documenting) independently. Projects to OpenAPI `oneOf` + `discriminator`,
 *   each branch carrying the discriminator as a `const`.
 * - `packaging` is a typed {@see Obj}: a nested object in a single value with
 *   per-child constraints, projecting to a typed `object` schema.
 * - `availability` and `dimensions` are free-form {@see ArrayHash} maps whose
 *   shapes are asserted by {@see Shape} composite schemas (`anyOf` / `allOf` of
 *   raw member schemas, value-validated by the core opis validator) — the
 *   assertional escape hatch when children aren't individually declared fields.
 *
 * Each attribute is stored as a single `json` column on {@see Release}.
 */
#[AsJsonApiResource(entity: Release::class, tags: ['Catalog'])]
final class ReleaseResource extends AbstractResource
{
    public static string $type = 'releases';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('catalogNumber')->required()->maxLength(40)->sortable(),

            // A discriminated union: the `medium` key picks the variant, and only
            // that variant's children validate. An unknown medium is a 422 at
            // /data/attributes/format/medium.
            OneOf::make('format')->nullable()->discriminator('medium')
                ->variant(
                    'vinyl',
                    Integer::make('rpm')->required()->min(16)->max(78),
                    Boolean::make('coloured'),
                )
                ->variant(
                    'cd',
                    Integer::make('discs')->required()->min(1),
                )
                ->variant(
                    'digital',
                    Str::make('codec')->required()->maxLength(16),
                    Integer::make('bitrateKbps')->min(32),
                ),

            // A typed object in one value: children declare their own constraints
            // and cascade to /data/attributes/packaging/<child> on violation.
            Obj::make('packaging')->nullable()->fields(
                Str::make('material')->required()->maxLength(40),
                Boolean::make('gatefold'),
            ),

            // Assertional shapes over free-form maps: `availability` must match at
            // least one branch (worldwide flag or an explicit region list) …
            ArrayHash::make('availability')->nullable()->constrain(
                Shape::anyOf(
                    Schema::ofType('object')
                        ->withProperties(['worldwide' => Schema::ofType('boolean')->withConst(true)])
                        ->withRequired(['worldwide']),
                    Schema::ofType('object')
                        ->withProperties([
                            'regions' => Schema::ofType('array')->withItems(Schema::ofType('string'))->withMinItems(1),
                        ])
                        ->withRequired(['regions']),
                ),
            ),

            // … while `dimensions` must satisfy every aspect schema at once (the
            // measurements and the unit are independent facets of one value).
            ArrayHash::make('dimensions')->nullable()->constrain(
                Shape::allOf(
                    Schema::ofType('object')
                        ->withProperties([
                            'widthMm' => Schema::ofType('number'),
                            'heightMm' => Schema::ofType('number'),
                        ])
                        ->withRequired(['widthMm', 'heightMm']),
                    Schema::ofType('object')
                        ->withProperties(['depthMm' => Schema::ofType('number')]),
                ),
            ),

            BelongsTo::make('album', 'albums'),
        ];
    }
}
