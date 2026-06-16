# Custom serializers, hydrators & handler decoration

The [field DSL](https://github.com/haddowg/json-api/blob/main/docs/fields.md) covers
most types: one `fields()` declaration drives both the read shape and the write shape.
But some wire shapes it cannot express — a member computed across two columns, one
request member fanned out to several entity properties, a hand-tuned `included`
policy. For those, you hand-write core's
[`SerializerInterface`](https://github.com/haddowg/json-api/blob/main/docs/serializers.md)
and/or [`HydratorInterface`](https://github.com/haddowg/json-api/blob/main/docs/hydrators.md)
and tell the bundle to use them instead of the DSL.

This page is the Symfony wiring for four escape hatches: overriding a resource's
serializer/hydrator, registering them standalone, customising a type's URL segment
(`uriType`), and decorating the single global operation handler. The serializer and
hydrator *contracts* are core's — link out for what their methods do; everything here
is the bundle's attribute arguments, DI requirements, and the route/`Location`
consequences.

## Override a resource's serializer or hydrator

A resource keeps its type, its routes, and its registration role, but delegates the
wire shape to a hand-written class. You name the override on the attribute:

```php
#[AsJsonApiResource(entity: Track::class, serializer: TrackSerializer::class)]
final class TrackResource extends AbstractResource
{
    public static string $type = 'tracks';
    // … fields() still declares the type, filters, sorts, relations …
}
```

— [`TrackResource`](../examples/music-catalog-symfony/src/Resource/TrackResource.php).
The `serializer:` and `hydrator:` arguments are independent: a resource may override
one, the other, or both. `TrackResource` overrides only its serializer (reads run
through [`TrackSerializer`](../examples/music-catalog-symfony/src/Serializer/TrackSerializer.php),
writes still hydrate from the fields), while
[`PlaylistResource`](../examples/music-catalog-symfony/src/Resource/PlaylistResource.php)
overrides only its hydrator (writes run through
[`PlaylistHydrator`](../examples/music-catalog-symfony/src/Hydrator/PlaylistHydrator.php),
reads still serialize from the fields). The two compose per type — the override is
never global (bundle [ADR 0023](adr/0023-custom-serializer-hydrator-per-resource.md)).

When you override, the resource's declared fields become **inert for that direction**:
the override owns the I/O, and the generic engine drives reads/writes through it
instead of the field inventory. The fields you keep declaring still matter for the
other direction and for [validation](validation.md) — `TrackResource` still declares
`fields()` so writes validate and hydrate, even though `TrackSerializer` owns reads.

### Why hand-write one

A serializer is the place for a shape the DSL can't compute. `TrackSerializer`
returns `getAttributes()` as a map of callables, derives `displayTitle` across
`trackNumber` + `title`, and adds a `nowPlaying` member **only** when the request
carries an authenticated user — the attribute *set* is request-dependent:

```php
public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
{
    $attributes = [
        // …
        'displayTitle' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): string
            => $track instanceof Track ? \sprintf('%d. %s', $track->trackNumber, $track->title) : '',
    ];

    if ($request->getAttribute('user') !== null) {
        $attributes['nowPlaying'] = static fn(mixed $track, JsonApiRequestInterface $request, string $field): bool
            => $track instanceof Track && $request->getAttribute('nowPlayingTrackId') === $track->id;
    }

    return $attributes;
}
```

A hydrator is the place for a fan-out the DSL can't express. `PlaylistHydrator`'s
`getAttributeHydrator()` fills the `title` column **and** derives a read-only `slug`
from the same member — "set one column from another", which no field declaration can
state:

```php
'title' => static function (mixed $playlist, mixed $value, array $data, string $field) use ($separator): Playlist {
    \assert($playlist instanceof Playlist);
    $title = \is_string($value) ? \trim($value) : '';
    $playlist->title = $title;
    $playlist->slug = self::slugify($title, $separator);

    return $playlist;
},
```

For *what* the serializer/hydrator methods must return, read core's
[serializers](https://github.com/haddowg/json-api/blob/main/docs/serializers.md) and
[hydrators](https://github.com/haddowg/json-api/blob/main/docs/hydrators.md) — the
bases (`AbstractSerializer`, `AbstractHydrator`), the relationship-rendering trait
(`RendersRelationsTrait`), and the `SerializerResolverAwareInterface` an override
opts into so the registry injects the resolver after construction — implement it
**only** if your serializer renders relationships or included resources (those need
the resolver to find sibling serializers); a flat attribute-only serializer does not.

### The override must be a registered service

This is the headline Symfony difference from core. Core registers a serializer with a
plain `new`; the bundle resolves the override **through the container**, so it can
take constructor dependencies. `TrackSerializer` takes a bound `$catalogTag`,
`PlaylistHydrator` a bound `$slugSeparator` — a successful read/write proves the
bundle resolved each *with* its dependency, which a plain `new` could not supply:

```php
final class TrackSerializer extends AbstractSerializer implements SerializerResolverAwareInterface
{
    public function __construct(private readonly string $catalogTag) {}
    // … getMeta() surfaces $this->catalogTag as meta.served_by …
}
```

Because resolution is by class-string through the container, **the override class
must be a registered service** of the right type. Both are checked at *container
build time*, not request time, by
[`ResourceLocatorPass`](../src/DependencyInjection/Compiler/ResourceLocatorPass.php):

| Condition | Result |
| --- | --- |
| `serializer:`/`hydrator:` class is not a registered service (or alias) | `LogicException` — "is not a registered service; register it so it can be resolved" |
| The class does not implement `SerializerInterface` / `HydratorInterface` | `LogicException` — "must implement …" |

Standard service autoconfiguration registers both (`TrackSerializer`/`PlaylistHydrator`
sit under the app's autowired `src/`), so in practice you just write the class and
name it. The
[`CustomSerializerHydratorTest`](../examples/music-catalog-symfony/tests/CustomSerializerHydratorTest.php)
asserts the round trip — `meta.served_by: music-catalog` on a `tracks` read, the
`road-trip-hits` slug derived on a `playlists` write — and that the override stays
per-type (a `playlists` read carries no `served_by`).

## Register a serializer or hydrator standalone

You can register a serializer or hydrator for a type that has **no** resource at all,
with `#[AsJsonApiSerializer]` / `#[AsJsonApiHydrator]`. That is the
[capability-composition](capability-composition.md) model — read that page for the
attributes, their tags, and the standalone recipes. Two consequences belong here,
where you are choosing between an override and a standalone pair:

- A standalone serializer is **serialize-only by default** — it opens *no* endpoints
  until its `operations` allow-list does, whereas an `AbstractResource` defaults to
  all five (the default-operations asymmetry; see
  [capability-composition](capability-composition.md) and [routing](routing.md)).
  [`ChartSerializer`](../examples/music-catalog-symfony/src/Serializer/ChartSerializer.php)
  opens exactly two: `#[AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchCollection, Operation::FetchOne])]`.
- A bare serializer/hydrator pair declares **no field inventory**, so writes through
  it are **not validated** (the [validator bridge](validation.md) only runs for an
  `AbstractResource`-backed type), and a fetch through it gets no field-derived
  `filters`/`sorts` ([data layer](data-layer.md)). If you want validation and
  query support, keep an `AbstractResource` and override the *direction* the DSL
  can't model — don't drop the resource.

A standalone serializer with no resource has no static `$uriType` to read, so its URL
segment falls back to its `getType()` unless it implements core's
`UriTypeAwareInterface` — `ChartSerializer` does, returning `'charts'` from
`uriType()` (bundle [ADR 0024](adr/0024-standalone-serializer-hydrator-capability.md)).

## `uriType` — a URL segment distinct from the type

A type's JSON:API `type` member and its **URL path segment** are separate. By default
they are identical; `uriType` lets them differ — a plural URL for a singular type
(`/books` for type `book`), or a kebab-cased path. `uriType` is a **core** static on
`AbstractResource` (`public static string $uriType`); this page owns its
**route/Location consequences in the bundle** (bundle
[ADR 0022](adr/0022-per-resource-uri-type-segment.md), referencing core ADR 0031).

```php
final class BookResource extends AbstractResource
{
    public static string $type = 'book';      // the JSON:API `type` member
    public static string $uriType = 'books';   // the URL segment → /books/{id}
}
```

The route loader reads `$uriType` **statically** from the class-string, exactly as it
reads `$type` — no instantiation. Only the *path* changes; everything keyed on the
type stays the type:

| Concern | Uses |
| --- | --- |
| Route **paths** (`/{seg}`, `/{seg}/{id}`) | `uriType` |
| Route **names** (`jsonapi.{type}.{action}`) | the JSON:API `type` |
| `_jsonapi_type` route default + operation dispatch | the JSON:API `type` |
| The rendered `data.type` member | the JSON:API `type` |
| The create `Location` header + convention self/related links | `uriType` |

The `Location` header on a `201` is built from the segment:
`$server->baseUri() . '/' . $uriType . '/' . $serializer->getId($entity)` (see
[`CrudOperationHandler`](../src/Operation/CrudOperationHandler.php)), resolving
`uriType` via the resource and **falling back to the type** for a bare
serializer/hydrator pair that declares no resource. See [routing](routing.md) for the
full generated route set.

> **Docs-only:** the music-catalog example app overrides no `uriType` (core's example
> domain keeps types and segments identical), so this section is illustrated in prose
> with a `book`/`books` sketch and **not** witnessed by a CI test. Every other snippet
> on this page is lifted from the CI-run example app.

## Decorate the global handler

A single generic
[`CrudOperationHandler`](../src/Operation/CrudOperationHandler.php) drives every
operation for every type over the [provider/persister SPI](data-layer.md) — there is
no per-type handler registry. When you need **cross-cutting** behaviour around
operation handling (an audit log on every write, a soft-delete that turns `DELETE`
into an update, a custom envelope), decorate that one service with Symfony's
`#[AsDecorator]`:

```php
#[AsDecorator(CrudOperationHandler::class)]
final class AuditingOperationHandler implements OperationHandlerInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly OperationHandlerInterface $inner,
    ) {}

    public function handle(
        JsonApiOperationInterface $operation,
    ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse {
        // intercept the operations you care about; delegate the rest:
        if ($operation instanceof CreateResourceOperation) {
            // … audit the write …
        }

        return $this->inner->handle($operation);
    }
}
```

This works because the [`ServerFactory`](../src/Server/ServerFactory.php) injects the
handler **by service id** (`service(CrudOperationHandler::class)`) and calls
`withHandler()` with it; Symfony's decoration swaps that id transparently, so every
server picks up the decorator with no extra wiring (bundle
[ADR 0028](adr/0028-handler-override-via-service-decoration.md)). Reproduce core's
`OperationHandlerInterface::handle()` exactly: a single `JsonApiOperationInterface`
argument and the closed union of six response value objects above (dispatch on the
operation type with `instanceof`, as [`CrudOperationHandler`](../src/Operation/CrudOperationHandler.php)
does) — see core
[operations](https://github.com/haddowg/json-api/blob/main/docs/operations.md).

**Reach for decoration last.** Per-type customization should normally compose through
the SPIs — a higher-priority [`DataProvider`/`DataPersister`](data-layer.md) shadowing
the Doctrine fallback for one type — or through the serializer/hydrator overrides
above. Decorate the handler only for behaviour that genuinely spans operations or
types; for one type's read shape, a serializer override is simpler and more direct.

## Next / see also

- [resources](resources.md) — `#[AsJsonApiResource]` and where `serializer:`/`hydrator:`/`uriType` sit on a resource.
- [capability-composition](capability-composition.md) — registering a serializer/hydrator standalone, and the default-operations asymmetry.
- [routing](routing.md) — how `uriType` and the `Operation` allow-list shape the generated routes.
- [data-layer](data-layer.md) — the provider/persister SPI, the preferred customization seam before handler decoration.
- [validation](validation.md) — why a standalone serializer/hydrator pair is not validated.
- Core [serializers](https://github.com/haddowg/json-api/blob/main/docs/serializers.md) · [hydrators](https://github.com/haddowg/json-api/blob/main/docs/hydrators.md) · [capability-composition](https://github.com/haddowg/json-api/blob/main/docs/capability-composition.md) · [operations](https://github.com/haddowg/json-api/blob/main/docs/operations.md) — the contracts this page wires into Symfony.
