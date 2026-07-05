# Composite attributes

The core composite attribute types — [`Obj`, `OneOf`](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#obj)
and the [`Shape` constraint](https://github.com/haddowg/json-api/blob/main/docs/constraints.md#shape-composite-schema-constraints)
— let a resource expose a structured attribute stored as **one value**: a typed
nested object, a discriminated union, or a free-form map whose overall shape is
asserted by a composite JSON Schema. Core defines the types and their semantics;
this page covers what the bundle adds — how each validates, and how the values
persist through Doctrine.

The three kinds at a glance:

```php
// A typed nested object in one value — children are declared fields.
Obj::make('address')->nullable()->fields(
    Str::make('street')->required(),
    Str::make('city')->required(),
    Str::make('postcode')->required()->maxLength(10),
),

// A discriminated union — `kind` selects which variant's children apply.
OneOf::make('block')->nullable()->discriminator('kind')
    ->variant('heading', Str::make('text')->required(), Integer::make('level')->min(1)->max(6))
    ->variant('image', Url::make('src')->required(), Str::make('alt')),

// A free-form map, its shape asserted by raw member schemas.
ArrayHash::make('contact')->nullable()->constrain(
    Shape::oneOf($emailShape, $phoneShape)->discriminator('kind'),
),
```

## How each kind validates

The split follows the constructive/assertional divide:

**`Obj` and `OneOf` children run through the
[Symfony Validator bridge](validation.md)** — the same translated cascade as a
[`Map`'s children](validation.md#the-nested-map-cascade). Each child's constraint
vocabulary translates to native Symfony constraints, and a violation points at the
child: `/data/attributes/address/city`, `/data/attributes/block/level`. For a
`OneOf`, only the **selected variant's** children are validated, and an unknown or
missing discriminator is itself the violation — a `422` pointing at
`/data/attributes/block/kind`.

**A `Shape` is value-validated by core's `SchemaValueValidator`** (opis), not by
constraint translation — its members are raw JSON Schema no Symfony constraint can
express. The bundle wires the validator into `ResourceValidator` automatically
when `opis/json-schema` is installed, and each violation's pointer extends the
field's own: a missing member of the matched variant surfaces under
`/data/attributes/contact/...`.

Two wiring notes:

- The `Shape` pass is **independent of** the [`json_api.schema_validation`
  structural linter toggle](validation.md) — a `Shape`'s value validation is on
  whenever opis is present, because the declaration exists solely to be enforced.
- Without opis a `Shape` still **documents** (its combinator projects into the
  OpenAPI schema); it just doesn't validate. Install `opis/json-schema` to get
  the `422`s.

Design records: ADR 0111 (the `Obj`/`OneOf` cascade) and ADR 0112 (`Shape` value
validation through the core validator).

## Storage: one `json` column

A composite attribute is **one value** — the natural Doctrine mapping is a single
`json` column with scalar children:

```php
#[ORM\Entity]
class Release
{
    public function __construct(
        // …
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $format = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $packaging = null,
    ) {}
}
```

No custom DBAL type is involved: the whole object round-trips as one JSON
document, an `Obj`'s partial `PATCH` merges per-child before the column is
written, and an explicit `null` clears it. A child value that needs a richer PHP
type than JSON scalars (a `DateTime` inside the object, a value object) rides the
field-level `serializeUsing()`/`fillUsing()` escape hatch rather than a recursive
column type — the same pattern the example app's `releaseInfo` map uses.

## Worked example

The example app's `releases` resource
([`ReleaseResource`](../examples/music-catalog-symfony/src/Resource/ReleaseResource.php)
over [`Release`](../examples/music-catalog-symfony/src/Entity/Release.php))
showcases all three kinds on one type — a `OneOf` format discriminated by
`medium` (vinyl / cd / digital), an `Obj` packaging, and `Shape`'d
availability/dimensions maps — each a single `json` column, and each keyword
(`oneOf`, `anyOf`, `allOf`, `discriminator`) visible in the served OpenAPI
document. The dual-provider conformance witness is
[`CompositeConformanceTestCase`](../tests/Functional/CompositeConformanceTestCase.php),
which runs the same validation-pointer and json-column round-trip assertions
against the in-memory and Doctrine kernels.

## Next

- [Validation](validation.md) — the bridge, the `422` shape, and the translation map.
- Core [field types](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#obj)
  — `Obj`/`OneOf` semantics (merge, discriminator fallback, OpenAPI projection).
- Core [constraints](https://github.com/haddowg/json-api/blob/main/docs/constraints.md#shape-composite-schema-constraints)
  — the `Shape` builders and the `SchemaValueValidator` execution route.
