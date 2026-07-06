# The Symfony Validator bridge and the optional schema linter

Core declares a rich constraint vocabulary on your fields — `required()`, `min()`,
`minItems()`, `emailFormat()`, `compareWith()`, `when()` — but it **never executes
it**. The constraints are metadata: a serializer/hydrator reads them for
documentation and schema generation, not enforcement. This bundle supplies the
missing half — a **Symfony Validator bridge** that translates that vocabulary into
real Symfony rules, runs them against every create/update document, and renders
violations as JSON:API `422` errors with a `source.pointer` into the request.

For the constraint vocabulary itself — what each constraint *means*, the
create/update `Context`, and the `constrain()` escape hatch — read core's
[constraints](https://github.com/haddowg/json-api/blob/main/docs/constraints.md).
This page documents only the Symfony side: the wiring, the two validation passes,
the translation map, the `422` rendering, the extension point, and the (separate)
opis structural linter.

## It is optional — and silent when absent

The bridge depends on `symfony/validator`, which is a `suggest` dependency, not a
hard one. The whole bridge is wired only when the validator is installed — this is
**bundle-internal auto-wiring** (it runs in the bundle's DI extension, not a file you
edit); installing `symfony/validator` is the only action on your side:

```php
// bundle-internal (src/JsonApiBundle.php) — registered only when symfony/validator is present
if (\interface_exists(\Symfony\Component\Validator\Validator\ValidatorInterface::class)) {
    $services->set(JsonPointerBuilder::class);
    $services->set(ConstraintTranslator::class)/* … */;
    $services->set(ResourceValidator::class);
}
```

`CrudOperationHandler` takes the `ResourceValidator` as an **optional** constructor
argument (`->nullOnInvalid()`). When the validator is absent it resolves to `null`,
and the handler's validation step is a no-op:

```php
// src/Operation/CrudOperationHandler.php
private function validate(Server $server, string $type, JsonApiRequestInterface $body, bool $creating): void
{
    if ($this->validator === null) {
        return; // symfony/validator not installed — writes run unvalidated
    }
    // …
}
```

> **Without `symfony/validator`, writes run unvalidated — silently.** No exception,
> no warning: a `POST` with a missing required attribute or a malformed email
> succeeds with `201`. Declared constraints are *not* enforced by default. If you
> rely on them, install `symfony/validator` (see the optional-dependency matrix in
> [configuration](configuration.md)).

There is a **second** gating condition. Validation only runs for a type backed by
an `AbstractResource` — the handler asks `TypeMetadataResolver::resourceFor()` for
the resource and skips validation when it returns `null`:

```php
$resource = $this->types->resourceFor($server, $type);
if ($resource === null) {
    return; // a bare serializer/hydrator pair declares no field inventory to validate
}
```

A type assembled from a **standalone serializer/hydrator pair** (see
[capability-composition](capability-composition.md)) declares no field inventory,
so writes through it are not validated. That is a real behavioural gap a
capability-composition user must know about.

## The document-first pass

The first pass runs **before hydration**, against the request's
`data.attributes` array exactly as the client sent it — so a violation maps cleanly
to `/data/attributes/<name>` without first reshaping the entity. The work lives in
[`ResourceValidator::validate()`](../src/Validation/ResourceValidator.php).

For each attribute field (the `Id` field and relationships are not attributes, so
they are validated separately — see [id format, below](#id-format-both-directions);
read-only fields are skipped per create/update context), the bridge builds one
Symfony `Collection` field entry, then validates the attributes array against a
`Collection(fields: …, allowExtraFields: true)` — unknown attributes are ignored,
matching the hydrator.

> **Read-only skip is request-aware.** A field may be `readOnly(fn => …)` —
> read-only *for some callers only* (see [authorization](authorization.md)). The
> validation skip consults the **same** request-aware gate the hydrator does
> (`isReadOnlyFor($creating, $request)`), so validation and hydration stay
> consistent: a field that is read-only for this caller is skipped in **both** — it
> is never validated-but-never-hydrated, which would surface a spurious 422 the
> write could not satisfy.

Two mechanisms combine in each field entry, and it is worth keeping them apart:

**1. Presence and nullability — resolved, not translated.** Core's `Required` and
`Nullable` constraints are *not* sent through the translation table. They are
resolved by `ResourceValidator` against the create/update `Context` into the
Symfony `Collection` wrapper:

| Field declaration | On create (`POST`) | On update (`PATCH`) |
|---|---|---|
| `->required()` | `Required` + `NotBlank` (present and non-empty) | `Optional` + `NotBlank` (may be omitted; if supplied, non-empty) |
| present, non-nullable | `NotNull` prepended (an explicit `null` is rejected) | same |
| `->nullable()` | `NotBlank(allowNull: true)` | same |

Presence is mandatory only on create; a `PATCH` never requires a member, so a
partial update may legitimately omit a required attribute. The
[`tracks`](../examples/music-catalog-symfony/src/Resource/TrackResource.php)
resource declares `Str::make('title')->required()`:

```php
// POST /tracks with no `title` → 422 at /data/attributes/title
// PATCH /tracks/1 omitting `title` → 200 (partial update, no member required)
```

**2. Value rules — translated.** Every other constraint on the field is run through
[`ConstraintTranslator`](../src/Validation/ConstraintTranslator.php) and added to
the field's constraint list. `tracks` again: `Integer::make('trackNumber')->min(1)`
becomes a `GreaterThanOrEqual(1)`, and `ArrayList::make('genres')->minItems(1)`
becomes a `Count(min: 1)`:

```php
// POST /tracks with trackNumber: 0 → 422 at /data/attributes/trackNumber
// POST /tracks with genres: []    → 422 at /data/attributes/genres
```

### Merge-before-validate on update

A `PATCH` is partial — the client may send only the attributes it is changing. But a
cross-field rule (`compareWith`) or a conditional rule (`when`) needs to see the
*whole* attribute state to evaluate correctly, not just the handful of members in the
patch. So on update the validator **folds the stored resource's wire-form attributes
under the incoming partial** before validating: it reads the current attribute map
off the loaded entity, `array_merge`es the incoming attributes on top, and validates
the **merged** map. Two consequences:

- a cross-field or conditional rule evaluates against the full merged state — e.g.
  `availableUntil > availableFrom` still fires when the patch sends only
  `availableUntil` and `availableFrom` comes from the stored row;
- an incoming explicit `null` still wins (the partial is merged last), and a stored
  `null` is dropped before the merge so an unset stored value doesn't spuriously
  satisfy a presence check.

On create there is no stored object, so the incoming document is validated as-is. The
work is `ResourceValidator::validate(…, ?object $existingObject, array $existingPivots)`.

## The 422 response

Each Symfony violation becomes a core `Error` (status `422`, code
`VALIDATION_FAILED`, `detail` = the Symfony message), with its `source.pointer`
built by [`JsonPointerBuilder`](../src/Validation/JsonPointerBuilder.php) from the
bracketed property path (`[title]` → `/data/attributes/title`, RFC-6901-escaped).
All violations across the document are collected and thrown together as one
[`ValidationFailed`](../src/Validation/ValidationFailed.php) — a core
`AbstractJsonApiException` carrying status `422` — which the route-scoped exception
listener renders (see [errors](errors.md)).

Because every error in the bag declares `422`, the whole response is `422` (not a
rounded-down `400`); this relies on core's error-document status fidelity. A worked
multi-violation body:

```http
POST /tracks
Content-Type: application/vnd.api+json

{ "data": { "type": "tracks", "attributes": { "trackNumber": 0, "durationSeconds": 10, "genres": ["rock"] } } }
```

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/vnd.api+json

{ "errors": [
  { "status": "422", "code": "VALIDATION_FAILED", "title": "Unprocessable Entity",
    "detail": "This field is missing.", "source": { "pointer": "/data/attributes/title" } },
  { "status": "422", "code": "VALIDATION_FAILED", "title": "Unprocessable Entity",
    "detail": "This value should be greater than or equal to 1.",
    "source": { "pointer": "/data/attributes/trackNumber" } }
] }
```

## Id format (both directions)

The `Id` field's format shortcuts (`uuid()`/`ulid()`/`numeric()`/`pattern()`)
declare a format constraint the bridge enforces on a write — on the **wire** id,
before any [encoder decode](resources.md#encoded-resource-ids) — in two directions:

| What | Validated against | Pointer |
|---|---|---|
| a client-supplied `data.id` | the **owning** resource's id format | `/data/id` |
| a relationship linkage id (`{ "type": T, "id": X }`) in a whole-resource write | the **related** type `T`'s id format | `/data/relationships/<rel>/data[/<n>]/id` |
| a linkage id at a relationship-mutation endpoint (`PATCH`/`POST`/`DELETE …/relationships/{rel}`) | the **related** type `T`'s id format | `/data/id` or `/data/<n>/id` |
| a `belongsToMany` linkage member's pivot `meta` field | the relation's **writable** pivot fields' constraints (create context for a genuinely-new member, update context for one already in the relationship — see below) | `/data/relationships/<rel>/data[/<n>]/meta/pivot/<field>` or `/data[/<n>]/meta/pivot/<field>` |

For a polymorphic relation the format is resolved from each linkage's own `type`
member. The bridge resolves a type → its resource → `Id` field → declared format
constraints through the shared `IdEncoderResolver`, then runs them through the same
`ConstraintTranslator`. A type whose id declares no format passes any id; a
generated or store-provided id never reaches this pass (only a client wire id does).

The owning-`data.id` check only runs when the type **accepts** a client id
(`allowClientId()` / `requireClientId()`). A type that forbids client ids (the
default) rejects *any* supplied id with a `403` `ClientGeneratedIdNotSupported`
regardless of its format — so the bridge does not pre-empt that uniform `403` with a
`422` for malformed ids only. The linkage checks run on both whole-resource writes
and the dedicated relationship-mutation endpoints, so an identical malformed linkage
id `422`s on either surface. The work lives in `ResourceValidator::ownIdError()` /
`linkageErrors()` / `validateRelationshipLinkage()`.

A **pivot `belongsToMany`** validates each linkage member's pivot `meta` against the
relation's *writable* pivot fields' constraints, reusing the same `Required` /
`Nullable` / `Collection` machinery as attributes — a violation points at the linkage
meta. The validation **context is resolved per member**: a member already
in the relationship merges its stored pivot row under the incoming `meta` and
validates in **update** context, while a genuinely-new member validates the incoming
`meta` in **create** context. A member is "existing" when its related id is in the
relation's existing pivot map, which the validator reads through the provider's
`fetchRelationshipPivot()` seam. The practical payoff: a *required* writable pivot
field need not be re-sent on a reorder of an existing member — the merged stored row
carries it, so a partial pivot update doesn't false-`422` — while a genuinely-new
association row that omits a required field *does* `422` (before persist — never a
database NOT-NULL `500`). A **read-only** pivot field supplied in `meta` is ignored
(it is not in the writable set, and the meta Collection allows extra fields, so it
never raises) — exactly how a read-only attribute is handled. The example app's
[`PlaylistResource::orderedTracks`](../examples/music-catalog-symfony/src/Resource/PlaylistResource.php)
is the live witness: `Integer::make('position')->required()->min(1)` (a new member
must carry it; an existing member need not re-send it) and
`Integer::make('weight')->compareWith('position', …)` (compared against the *merged*
pivot, so `weight` may be set without re-sending `position`). See
[relationships.md](relationships.md#writing-pivot-data) for the write convention.

## The constraint-translation map

`ConstraintTranslator::translate()` maps one core constraint VO to one or more
Symfony constraints. The VOs are **core** — link
[constraints](https://github.com/haddowg/json-api/blob/main/docs/constraints.md)
for the vocabulary; this table documents only the Symfony mapping.

| Core constraint | Symfony constraint | Notes |
|---|---|---|
| `In` / `NotIn` | `Choice` / `Choice(match: false)` | |
| `Min` / `Max` | `GreaterThanOrEqual` / `LessThanOrEqual` | |
| `ExclusiveMin` / `ExclusiveMax` | `GreaterThan` / `LessThan` | |
| `MultipleOf` | `DivisibleBy` | |
| `MinLength` / `MaxLength` | `Length(min:)` / `Length(max:)` | `min` clamped to `≥ 0`, `max` to `≥ 1` |
| `MinItems` / `MaxItems` | `Count(min:)` / `Count(max:)` | |
| `MinProperties` / `MaxProperties` | `Count(min:)` / `Count(max:)` | collapse to `Count` |
| `UniqueItems` | `Unique` | |
| `EmailFormat` | `Email` | strict → `Email(mode: STRICT)` only with `egulias/email-validator` (else HTML5) |
| `UrlFormat` | `Url` | protocols default `['http','https']`; `requireTld: true` where the option exists (validator ≥ 7.1) |
| `UuidFormat` | `Uuid` | restricted to the declared version (1–8); otherwise any version |
| `IpFormat` | `Ip` | `version` → `Ip::V4` / `V6` / `ALL` |
| `Pattern` / `SlugFormat` | `Regex` | wrapped in `~…~` delimiters, the `~` escaped |
| `Each` | `All` | inner constraints translated recursively |
| `Sequentially` | `Sequentially` | composition; inner constraints translated |
| `AtLeastOneOf` | `AtLeastOneOf` | each alternative is one (possibly `Sequentially`-wrapped) constraint |
| `When` | `Callback` | see below |
| `After` / `Before` / `Between` | `Callback` | see below |
| `UniqueEntity` | doctrine-bridge `UniqueEntity` | entity-level pass — see below |
| *anything else* | extension translator | first `supports()` match; else `LogicException` |

`Required` and `Nullable` are deliberately **absent** from this table — they are
resolved by presence/nullability, not translated (see the document-first pass).

### Closure-carrying constraints via `Callback`

A few core constraints carry PHP closures that no stock Symfony constraint accepts,
so each translates to a `Callback` whose body runs the closure at validation time:

- **`When`** evaluates its condition closure against the value and, only when it
  returns `true`, validates the value against the translated inner constraints. The
  condition is **request-aware**: it receives the value first and the inbound request
  second (`fn($value, $request)`), so a rule can branch on the caller — and because
  the value stays first, an existing `fn($value)` closure keeps binding unchanged. A
  presence rule (`required()`/`nullable()`) wrapped in a `when()` is honoured by
  presence resolution, so a field can be *conditionally required per caller*:

  ```php
  Str::make('clearance')->nullable()->when(
      static fn(mixed $value, ?JsonApiRequestInterface $request): bool => $request?->getHeaderLine('X-Role') === 'admin',
      static fn(Str $field) => $field->required(),
  )
  // admin omitting `clearance` → 422 at /data/attributes/clearance
  // a non-admin omitting it → accepted (the condition is false for it)
  ```

  > The request is threaded only on the **write document** path. The filter-side
  > validator, the id-format check and the entity-level pass (`UniqueEntity` and the
  > like) pass `null` as the second argument — their `when()` conditions stay static.
- **`After` / `Before` / `Between`** coerce the value to a `\DateTimeImmutable`,
  skip an absent/empty/unparseable value, and resolve the bound **at validation
  time** — so a closure bound such as "now" reflects the moment of the request.

The [`albums`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php)
resource declares a closure bound on `releasedAt`:

```php
DateTime::make('releasedAt')
    ->before(static fn(): \DateTimeImmutable => new \DateTimeImmutable())
    // …
```

```php
// PATCH /albums/1 with releasedAt: 2999-01-01… → 422 (not in the future)
// PATCH /albums/1 with releasedAt: 2000-01-01… → 200
```

The example app pins this under a frozen `symfony/clock` so the "now" bound is
deterministic in CI.

### Cross-field comparison (`CompareField`)

`CompareField` compares one attribute against a *sibling* attribute — which the
per-field `Collection` cannot see, since it validates each value in isolation. So
`ResourceValidator` evaluates `compareWith()` rules **at the document level**, after
the `Collection` pass, reading both values straight from the attributes array. The
comparison is skipped when either field is absent or `null` (presence is the
`Required` rule's job), and on failure raises a `422` pointing at the **owner**
field.

The example shows both flavours. `albums.availableUntil` is a *directional*
`GreaterThan availableFrom`:

```php
Date::make('availableUntil')
    ->nullable()
    ->compareWith('availableFrom', Comparison::GreaterThan),
```

```php
// availableFrom: 2030, availableUntil: 2020 → 422 at /data/attributes/availableUntil
```

[`users.passwordConfirm`](../examples/music-catalog-symfony/src/Resource/UserResource.php)
is a *non-directional* `EqualTo password`:

```php
// password: 'longpassword1', passwordConfirm: 'different'
//   → 422 at /data/attributes/passwordConfirm
```

## The entity-level pass

Some rules cannot run on the request document — they need the persisted object or
the database. Uniqueness is the canonical case. The bridge handles these in a
**second pass**,
[`ResourceValidator::validateEntity()`](../src/Validation/ResourceValidator.php),
which the write handler calls **after hydration and before commit**.

A constraint joins this pass by implementing the bundle marker
[`EntityConstraintInterface`](../src/Validation/EntityConstraintInterface.php). The
bridge skips such constraints in the document-first pass, then in the entity pass
translates them to Symfony **class** constraints and validates the hydrated entity
object.

The bundled rule is
[`UniqueEntity`](../src/Validation/Constraint/UniqueEntity.php), attached with
core's `constrain()`:

```php
// UserResource
Email::make('email')->required()->strict()->constrain(new UniqueEntity(['email'])),
```

It translates to doctrine-bridge's `UniqueEntity`, which queries the entity's
repository and — on update — excludes the current record by its identifier:

```php
// POST /admin/users with a seeded email → 422 at /data/attributes/email
// PATCH /admin/users/1 re-supplying the user's own email → 200 (excludes self)
```

> **`UniqueEntity` requires `symfony/doctrine-bridge` at runtime** — that package
> ships Symfony's `UniqueEntity` constraint and validator, which `ConstraintTranslator`
> translates to. It is listed in the bundle's own `composer.json` `suggest`
> (see the [configuration](configuration.md) matrix); the example app depends on it
> directly (it also arrives transitively via `doctrine/doctrine-bundle`). Without
> it, a `UniqueEntity` VO cannot be translated.

## The nested-`Map` cascade

A structured `Map` attribute's direct children validate by **implicit recursion** —
no `Valid` marker required. The bridge builds a nested `Collection` mirroring the
top-level one (same `allowExtraFields`, same per-child `Required`/`Nullable`
resolution by context), so a child violation nests cleanly:
`/data/attributes/<map>/<child>`. The example's `albums.releaseInfo` Map leaves its
`label` child unconstrained and marks `catalogueNumber` read-only, so no child
violation arises there — add a constraint to a Map child (the cascade itself is
exercised by the bundle's own conformance suite) to see the nested pointer.

> The cascade is **one level deep, by design**: a `Map` child that is
> itself a `Map`, or a list-of-objects, is *not* descended into. See core's
> [field-types](https://github.com/haddowg/json-api/blob/main/docs/field-types.md)
> doc for the `Map` field itself.

## Custom constraints: the extension point

For a constraint VO outside core's built-in vocabulary, register a translator.
Implement
[`ConstraintTranslatorInterface`](../src/Validation/ConstraintTranslatorInterface.php)
— `supports()` plus `translate(): list<Constraint>` — and tag it (autoconfigured
from the interface, but only when `symfony/validator` is installed):

| Tag constant | Value |
|---|---|
| `JsonApiBundle::CONSTRAINT_TRANSLATOR_TAG` | `haddowg.json_api.constraint_translator` |

`ConstraintTranslator` consults the registered translators in priority order (first
`supports()` match wins) for anything it does not translate itself; if none
matches, it raises a clear `LogicException` naming the constraint class. This is the
typed replacement for the removed `$id`-keyed `Custom` hatch — a translator matches
on the constraint's **class**, not a string id. (An entity-level custom rule uses
the same seam, additionally implementing `EntityConstraintInterface`.)

### Native Symfony constraints without a translator: `NativeConstraints`

For a one-off Symfony-native rule, skip the bespoke VO + translator and wrap the raw
`Assert\*` constraints in
[`NativeConstraints`](../src/Validation/Constraint/NativeConstraints.php), attached with
`constrain()`:

```php
Str::make('secret')->constrain(NativeConstraints::make([new Assert\NotCompromisedPassword()]));
```

The translator recognises the carrier and passes the wrapped constraints straight to the
validator — so they run in the same `422` pass (and, because the filter-value validator
shares the translator, on `filter[…]` values too) with nothing to register. Scope it with
`->onCreate()` / `->onUpdate()`.

A native rule is **invisible to the generated OpenAPI/JSON Schema** by default (it
validates but doesn't document). Declare the value schema it implies with `->schema()` — a
closure over core's neutral `Schema` VO (it rides core's
[`ProvidesJsonSchema`](https://github.com/haddowg/json-api/blob/main/src/Resource/Constraint/ProvidesJsonSchema.php)
seam) — when you want it in the document:

```php
Str::make('secret')->constrain(
    NativeConstraints::make([new Assert\NotCompromisedPassword()])
        ->schema(static fn(Schema $s): Schema => $s->withMinLength(8)),
);
```

Keep the fragment framework-neutral so a byte-compatible twin (the Laravel `LaravelRules`
carrier) emits the identical schema. `NativeConstraints` couples the field to Symfony, so
prefer a core constraint when one exists and reach here only for a genuinely native rule.

## Strict-email degradation

`EmailFormat(strict)` translates to `Email(mode: STRICT)` **only** if
`egulias/email-validator` is installed; otherwise it **silently degrades to HTML5
mode** rather than failing the request — which is what keeps that package a
`suggest`. The `users.email` field uses `->strict()`, so the example app suggests
`egulias/email-validator` for true RFC-5322 checking.

## The opis structural linter is a different layer

The Symfony Validator bridge answers a *semantic* question: do the attribute values
satisfy the declared constraints? → `422`. There is a separate, optional **opis
structural linter** answering a *prior* question: is this even a well-formed
JSON:API document? → `400`.

Enable it with `json_api.schema_validation: true` (default `false`). It runs in the
`RequestListener` **before** the handler, validating the parsed write body against
the JSON:API JSON Schema. The opis machinery (`DocumentValidator`,
`VendoredSchemaProvider`) is **core** — the bundle merely wires it; link core's
[schema-validation](https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md).

```yaml
# config/packages/json_api.yaml
json_api:
    schema_validation: true   # requires opis/json-schema (else the build fails)
```

Enabling the toggle without `opis/json-schema` throws a `LogicException` **at
container build** — a misconfiguration surfaces immediately, not at the first
write. A disallowed resource-object member that the hydrator would otherwise
silently ignore is rejected when the linter is on:

```php
// POST /playlists with an extra `bogus` member
//   schema_validation: true  → 400 (rejected by the linter)
//   schema_validation: false → 201 (the hydrator ignores `bogus`)
```

Keep the two distinct: a `400` is structural ("not a JSON:API document"), a `422`
is semantic ("a value broke a rule").

## Filter values reuse this bridge (for a `400`)

The same `ConstraintTranslator` gives **filter** value constraints teeth. A filter
can declare value constraints (`Where::make('year')->integer()`,
`WhereIdIn::make()->uuid()`, …); the bundle translates them through this bridge and
checks a client-supplied `filter[<key>]` value **before** the filter reaches the
data layer, rejecting a mistyped value with a `400`
(`source.parameter`) — *not* a `422`, because a filter is a query *parameter*, not a
document. It is optional in the same way (inert without `symfony/validator`). This
turns the provider's unhelpful default for a type-mismatched value (a silent
non-match, or a Doctrine PDO `500` on a strict driver) into a deliberate client
error with `source.parameter`. See
[data-layer → Validating filter values](data-layer.md#validating-filter-values).

## Beyond the declarative bridge: imperative checks, custom pointers, localisation

The declarative bridge covers the common cases; three things it deliberately does
**not** expose declaratively, and how to reach each:

**Imperative validation.** For a rule no constraint VO expresses — a multi-field
invariant, a check against another aggregate, a `409` instead of a `422` — use a
[lifecycle before-hook](lifecycle-hooks.md) (`beforeCreate`/`beforeUpdate`/`beforeSave`,
or the matching event). Each receives the **hydrated entity** and a `HookContext`
carrying the live request (`beforeUpdate` also gets the pre-change `$original`
snapshot), so you can run arbitrary logic and throw. To emit a multi-violation `422`
with pointers, build the `Error`s yourself and throw a
[`ValidationFailed`](../src/Validation/ValidationFailed.php) — the same exception the
bridge throws, so it renders identically:

```php
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Validation\ValidationFailed;

public function beforeUpdate(object $entity, object $original, HookContext $context): void
{
    if ($entity->getDiscount() > $entity->getPrice()) {
        throw new ValidationFailed([
            new Error(
                status: '422',
                code: 'VALIDATION_FAILED',
                title: 'Unprocessable Entity',
                detail: 'Discount cannot exceed price.',
                source: ErrorSource::fromPointer('/data/attributes/discount'),
            ),
        ]);
    }
}
```

This runs **after** hydration (Symfony's `withValidator` equivalent runs
pre-hydration — for a pre-hydration gate the only seam is the request-wide
`ServingEvent`).

**Custom pointer / `source.parameter`.** The bridge's pointer is derived
deterministically from the property path and is **not** overridable on a declared
constraint. When you need a different `source.pointer`, or a `source.parameter`
instead, build the `Error` yourself — in a before-hook as above, or by mapping the
exception with an [`ExceptionMapperInterface`](errors.md) — and set
`ErrorSource::fromPointer(...)` / `fromParameter(...)` to whatever you like.

**Localising messages.** A violation's `detail` is the Symfony constraint message,
which flows through Symfony's translator (domain `validators`). So enabling
`framework.translator` and shipping a `translations/validators.<locale>.xlf`
catalogue **localises the `422` `detail` for free** — including the closure-built
date-bound and `Between` messages. Two caveats: the error **`title`** is the fixed
reason phrase (`"Unprocessable Entity"`) and is *not* translated, and **native**
(non-validation) error objects — a `404`, a negotiation `415`, a mapped domain
exception — carry core's own English `title`/`detail` with no translation seam (to
localise those, set translated strings yourself in an `ExceptionMapperInterface`). A
custom per-constraint **message** is likewise not declaratively reachable (only
`UniqueEntity` carries a `message` option) — localise via the catalogue, or override
imperatively.

## Next / see also

- [data-layer](data-layer.md) — where the two validation passes sit in the
  `CrudOperationHandler` write flow, and where declared **filter value
  constraints** are validated (a `400`) before a filter reaches the provider.
- [errors](errors.md) — how `ValidationFailed` (`422`) and the linter's `400` reach
  the wire through the route-scoped exception listener.
- [relationships](relationships.md) — relationship-body validation (cardinality and
  mutability) is core's concern, distinct from this attribute bridge.
- [configuration](configuration.md) — the optional-dependency matrix
  (`symfony/validator`, `symfony/doctrine-bridge`, `egulias/email-validator`,
  `opis/json-schema`) and the `schema_validation` key.
- Core: [constraints](https://github.com/haddowg/json-api/blob/main/docs/constraints.md)
  (the vocabulary + `Context` + `constrain()`),
  [errors-and-exceptions](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md)
  (`Error` / `ErrorSource`), and
  [schema-validation](https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md).
