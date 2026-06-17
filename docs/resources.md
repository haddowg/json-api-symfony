# Resources, discovery & the `#[AsJsonApiResource]` attribute

A JSON:API *type* — its id, its attributes, its relations — is described by a
**resource** class extending core's `AbstractResource`. The core docs own that
vocabulary: see [resources](https://github.com/haddowg/json-api/blob/main/docs/resources.md)
for what `AbstractResource` is, [fields](https://github.com/haddowg/json-api/blob/main/docs/fields.md)
and [field-types](https://github.com/haddowg/json-api/blob/main/docs/field-types.md)
for what goes inside `fields()`, [ids](https://github.com/haddowg/json-api/blob/main/docs/ids.md)
for the id field, and [relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md)
for the relation DSL.

This page is about the **Symfony side**: how the bundle *discovers* your resource,
how the `#[AsJsonApiResource]` attribute carries the extra metadata Symfony needs,
and the compile-time guards you may hit while wiring one up. Write the resource the
way core teaches; register it as a service the way this page teaches, and you have
the full endpoint set.

## Zero-config discovery

There is no resource registry to edit and no `Server` to build by hand. Any service
whose class extends `AbstractResource` is **auto-tagged** for the bundle. The
bundle calls `registerForAutoconfiguration(AbstractResource::class)` and attaches
the public tag `haddowg.json_api.resource` (the constant
`JsonApiBundle::RESOURCE_TAG`), so the only thing your app has to do is make the
resource an autoconfigured service.

In the example app that is one stanza — register `src/` as autowired +
autoconfigured services and let discovery do the rest
([`services.yaml`](../examples/music-catalog-symfony/config/services.yaml)):

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    haddowg\JsonApiBundle\Examples\MusicCatalog\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'
            - '../src/MusicCatalogKernel.php'
```

Because resources are ordinary services, they can have real constructor
dependencies — the bundle resolves them through the container, not via `new`.

A minimal fetchable resource looks exactly like its core counterpart — the only
Symfony-specific line is the attribute mapping it to a Doctrine entity
([`ArtistResource`](../examples/music-catalog-symfony/src/Resource/ArtistResource.php)):

```php
#[AsJsonApiResource(entity: Artist::class)]
final class ArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(120)->sortable(),
            // …
            HasMany::make('albums')->type('albums')->dataOnlyWhenLoaded(),
        ];
    }
}
```

Register that, [import the routes](routing.md), and you have `GET /artists`,
`GET /artists/{id}`, `POST /artists`, `PATCH /artists/{id}`,
`DELETE /artists/{id}`, plus the relationship endpoints for `albums`.

### The all-five default

A discovered resource exposes **all five CRUD operations** by default —
`FetchCollection`, `FetchOne`, `Create`, `Update`, `Delete` — and the full set of
relationship endpoints for any relation it declares. You do not opt *in* to
endpoints; you opt *out* of the ones you do not want (the `operations` allow-list,
below, and per-relation exposure, covered in [relationships](relationships.md)).

This "register → get everything" default is specific to `AbstractResource`. A
type assembled from a **standalone serializer** instead defaults to *no*
operations (serialize-only) — that asymmetry, and the rest of the resource-less
model, lives in [capability-composition](capability-composition.md).

## The `#[AsJsonApiResource]` attribute

The attribute is **optional** — discovery already works without it. You add it to
carry metadata Symfony needs that the class itself cannot express: which Doctrine
entity backs the type, which named server(s) expose it, a per-type serializer or
hydrator override, or an operation allow-list. Its signature (abridged here — the
authz and response-header args are documented separately, below) lives in
[`AsJsonApiResource`](../src/Attribute/AsJsonApiResource.php):

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    public function __construct(
        public ?string $type = null,
        public string|array|null $server = null,
        public ?string $entity = null,
        public ?string $serializer = null,
        public ?string $hydrator = null,
        public array $operations = [],
    ) {}
}
```

| Argument | Type | Meaning |
| --- | --- | --- |
| `type` | `?string` | Declaration-site override of the static `$type`. Only needed in the rare case the wire type differs from the class's `$type`; normally omitted. |
| `server` | `string \| list<string> \| null` | The named server(s) exposing this type — a single name, a list, or `null` for the implicit `default` server (see [Server assignment](#server-assignment)). |
| `entity` | `?class-string` | The Doctrine entity the reference data layer reads and writes for this type. Inert unless `doctrine/orm` is installed (see [doctrine](doctrine.md)). |
| `serializer` | `?class-string` | A per-type serializer override — a registered service implementing core's `SerializerInterface` (see [custom serializers & hydrators](custom-serializers-hydrators.md)). |
| `hydrator` | `?class-string` | A per-type hydrator override — a registered service implementing core's `HydratorInterface`. |
| `operations` | `list<Operation>` | The exposed operation allow-list (empty = all five). |

> The constructor also carries the declarative-authorization arguments (`security`,
> `securityCreate`, …) documented in [authorization](authorization.md), and the
> declarative response-header arguments (`cacheHeaders`, `deprecation`, `sunset`,
> `sunsetLink`) documented in [configuration](configuration.md#response-headers-caching-and-deprecation) —
> both omitted from the snippet above for brevity.

A second job: the attribute **also tags a class that is *not* an `AbstractResource`
subclass** as a resource. So if you build a type from capabilities rather than the
`AbstractResource` sugar, the attribute is how you still mark the class — discovery
by base class and discovery by attribute are the two entry points.

### Server assignment

When your API runs more than one server (an admin surface alongside the public one,
say), `server` is how a type joins them. The implicit `default` server needs no
mention; name additional servers explicitly. The example's `albums` type is the
multi-server witness — exposed on **both** surfaces
([`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php)):

```php
#[AsJsonApiResource(entity: Album::class, server: ['default', 'admin'])]
final class AlbumResource extends AbstractResource
```

while `users` is admin-only:

```php
#[AsJsonApiResource(entity: User::class, server: 'admin')]
```

The server names you reference here must be declared under `json_api.servers` (or
be the literal `default`); referencing an undeclared server is a build-time
`LogicException`. Declaring the servers, mounting their routes, and the end-to-end
resolution are covered in [configuration](configuration.md),
[routing](routing.md), and [multi-server & testing](multi-server-and-testing.md)
respectively. This decision is bundle ADR 0034.

### The serializer / hydrator overrides

The field DSL cannot always express the wire shape you need, or you want a write to
do something the declarative hydrator cannot. Point `serializer`/`hydrator` at a
service and the bundle's single generic handler (the `CrudOperationHandler`, see
[the request lifecycle](lifecycle.md)) drives that type through your class instead of
the field inventory. In the example, `tracks` overrides its serializer and
`playlists` its hydrator:

```php
#[AsJsonApiResource(entity: Track::class, serializer: TrackSerializer::class)]
// …
#[AsJsonApiResource(entity: Playlist::class, hydrator: PlaylistHydrator::class)]
```

Both override services carry real constructor dependencies, bound in
[`services.yaml`](../examples/music-catalog-symfony/config/services.yaml) — a
successful read/write proves the bundle resolved them through the container rather
than `new`-ing them. The mechanics, and registering a serializer/hydrator with no
resource at all, are owned by
[custom serializers & hydrators](custom-serializers-hydrators.md).

### The operation allow-list

`operations` trims which CRUD endpoints a type serves. It takes a list of
[`Operation`](../src/Operation/Operation.php) enum cases (`FetchCollection`,
`FetchOne`, `Create`, `Update`, `Delete`); an empty list (the default for a
resource) means all five. An unexposed verb is simply **unrouted** — the router
404s/405s it before any handler runs. The allow-list *mechanism* — how a case
becomes a route, the per-capability defaults — is owned by [routing](routing.md)
and [capability-composition](capability-composition.md); the attribute here is just
where you declare it.

## `$type` vs `$uriType`

A resource's JSON:API **type** (the `type` member in every document) and its **URL
segment** are separate. `$type` is mandatory; `$uriType` (a core static on
`AbstractResource`, defaulting to `$type`) lets a `book` type be served at `/books`
without changing the document type. Both are read **statically** during the
compile pass — your resource is never instantiated to discover them — so they must
be static properties, not computed at runtime.

You rarely set `$uriType`. When you do, the route loader emits the URL with the URI
segment while keeping route *names* keyed on the JSON:API type; that route-emission
consequence, and the worked `book → /books` case, are owned by
[custom serializers & hydrators](custom-serializers-hydrators.md).

## Self links by convention

Two spec-recommended `self` links render by convention with no configuration:

- **Resource self** — every resource object (primary data *and* every `?include`'d
  resource) carries `data.links.self = {base_uri}/{uriType}/{id}`. It uses the URI
  segment, so a `book` type with `$uriType = 'books'` links to `/books/{id}` while
  the `type` member stays `book`. It is skipped when the id is empty (a
  not-yet-persisted echo) or when a hand-written `getLinks()` already supplies a
  `self` (which wins). Opt a resource out by overriding `emitsSelfLink(): bool` to
  return `false` — that resource then has no `data.links.self`, while the top-level
  document self is unaffected.
- **Top-level document self** — every data/resource document (single, collection,
  related, relationship, meta — but **not** error documents) carries
  `links.self` = the request URI. On a paginated collection the page's own self
  (carrying the resolved page params) wins, with `first`/`prev`/`next`/`last`
  preserved alongside.

Both links are storage-agnostic — they derive from the configured `base_uri`, the
`uriType`/type, the id and the request URI — so they are identical on every
provider. The behaviour lives in core (core ADR 0054); the bundle witnesses it
across the dual-provider conformance suites (bundle ADR 0047).

## Sourcing the resource id

Where a new resource's `id` comes from is governed by two orthogonal axes on the
`Id` field. **By default** a `POST` carrying a client `data.id` is rejected with a
`403`, and a `POST` *without* one is **store-provided** — the bundle sets nothing on
the entity and the store/DB assigns the id (a Doctrine `#[ORM\GeneratedValue]`
column, a database default). This replaces the old "stamp a UUID on every create"
behaviour: a plain `Id::make()` over an auto-increment entity just works, and the
`201` response (and `Location`) carry the id the database assigned.

**Axis 1 — client-id acceptance** (default: forbidden):

| Call | Effect |
| --- | --- |
| *(default)* | a client `data.id` is rejected — `403 ClientGeneratedIdNotSupported` |
| `allowClientId()` | a client `data.id` is *optional* — used (and format-validated) when supplied, generated otherwise |
| `requireClientId()` | a client `data.id` is *mandatory* — a create without one is `403 ClientGeneratedIdRequired` |

**Axis 2 — the fallback when the client supplies no id** (default: store-provided):

| Call | Effect |
| --- | --- |
| *(default)* | store-provided — the bundle sets nothing; the store/DB assigns the id |
| `generated()` | the bundle mints one from the declared format — `uuid()` → a v4 UUID, `ulid()` → a Crockford-base32 ULID (`generated()` on a non-self-generating format is a build-time `\LogicException`) |
| `generateUsing(fn(): string)` | a closure returns the storage key directly (full control; the result is set as-is, never decoded) |

```php
Id::make()                                          // store-provided (DB assigns)
Id::make()->uuid()->generated()                     // the app mints a v4 UUID
Id::make()->ulid()->generated()                     // the app mints a ULID
Id::make()->generateUsing(fn() => Id::generateUuid()) // a UUID, no route/format pin
Id::make()->requireClientId()                       // a natural key the client supplies
Id::make()->uuid()->allowClientId()->generated()    // client UUID if given, else minted
```

> Migrating from the old auto-UUID? A non-`GeneratedValue` string-id entity that
> must keep minting an id needs `generated()` (or `generateUsing()`); otherwise it
> will persist a blank id. Use `generateUsing(fn() => Id::generateUuid())` rather
> than `uuid()->generated()` when the entity already holds non-UUID ids you must not
> reject (the format shortcuts also pin the route `{id}` and add a format
> constraint).

### Id format validation

The `uuid()`/`ulid()`/`numeric()`/`pattern()` shortcuts declare a format constraint
the [Symfony Validator bridge](validation.md) enforces in **both directions** on a
write — *before* any decode:

- a client-supplied `data.id` is validated against the **owning** resource's id
  format (a violation is a `422` at `/data/id`); and
- every relationship **linkage** id (`{ "type": T, "id": X }`) is validated against
  the **related** type `T`'s id format (a violation is a `422` at
  `/data/relationships/<rel>/data[/<n>]/id`). For a polymorphic relation the format
  is resolved from each linkage's own `type`.

This needs `symfony/validator` installed (the bridge is a `suggest` dependency); a
type whose id declares no format passes any id.

## Encoded resource ids

The JSON:API `id` a client sees need not be the key your entity is stored under.
Attach an encoder to the `Id` field — `Id::make()->encodeUsing($codec)` — and the
rendered `id` (and every link) becomes `encode(storageKey)`, while the entity keeps
holding the real storage key (an integer PK, a binary UUID, …). `Id::matchAs($regex)`
(or the `uuid()`/`ulid()`/`numeric()`/`pattern()` shortcuts) constrains the route
`{id}` so a malformed id `404`s at routing. Encoders are user-supplied, and the
decode happens entirely in the reference Doctrine layer (the in-memory provider has
no encoder); the full storage-vs-wire boundary lives in
[doctrine § Encoded resource ids](doctrine.md#encoded-resource-ids-storage-key--wire-id).

## Compile-time guards

The bundle validates your wiring at **container build time**, not on a request, so
a misconfiguration fails the cache warm-up with a `\LogicException` that names the
fix rather than surfacing as a confusing 500 later. The ones you may hit from a
resource declaration:

| Failure | Raised when | Fix |
| --- | --- | --- |
| Unregistered override | `serializer`/`hydrator` names a class that is not a registered service | Register the override service so the container can resolve it (with its dependencies). |
| Wrong override type | the override class does not implement core's `SerializerInterface` / `HydratorInterface` | Make the class implement the right core contract. |
| Write without a hydrator | the type exposes `Create`/`Update` but has no hydrator | Add a hydrator (the resource's own, an override, or a standalone [`#[AsJsonApiHydrator]`](capability-composition.md#the-three-standalone-attributes)), or drop the write operations. |
| Unknown server | `server` references a name not declared under `json_api.servers` | Declare the server, or remove the reference. |
| Entity-mapping faults | `entity` is missing, undeterminable, or two types map to one entity | See [doctrine](doctrine.md), which owns the entity-map guards. |

The write-without-hydrator guard lives in `ResourceLocatorPass::validateWriteCapability()`;
the override and server guards alongside it in the same pass. Because they run at
build time, CI catches them before deployment.

## Beyond a resource

`AbstractResource` is the on-ramp, not the only road. You can override a single
capability (a custom serializer or hydrator, above), declare relations
independently of any resource, or skip the resource class entirely and assemble a
type from a serializer + hydrator + relations + provider + persister. That model —
and *why* a type is really just a bag of independent capabilities — is the subject
of [capability-composition](capability-composition.md).

## Next / See also

- [capability-composition](capability-composition.md) — compose a type from
  independent capabilities (and the serialize-only default).
- [routing](routing.md) — import the routes, the generated route set, and the
  operation allow-list mechanism.
- [doctrine](doctrine.md) — what the `entity` mapping wires up.
- [configuration](configuration.md) — declaring servers and the optional
  dependencies.
- Core: [resources](https://github.com/haddowg/json-api/blob/main/docs/resources.md),
  [fields](https://github.com/haddowg/json-api/blob/main/docs/fields.md),
  [relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md) —
  what goes *inside* the resource.
