# Composing a type from independent capabilities

[`AbstractResource`](resources.md) is the on-ramp: one class declares the fields,
relations, serializer and hydrator for a type, and registering it as a service gives
you the full endpoint set. But a JSON:API *type* is not an `AbstractResource` — it
is a set of **independent capabilities**, each of which the bundle discovers and
wires on its own:

| Capability | What it does | Registered by |
| --- | --- | --- |
| **serializer** | the read wire shape (primary data, linkage, `included`) | `#[AsJsonApiSerializer]` |
| **hydrator** | the write wire shape (id + attributes from the request body) | `#[AsJsonApiHydrator]` |
| **relations** | the type's relationships (for relationship/related endpoints) | `#[AsJsonApiRelations]` |
| **provider** | reads — fetch one / collection / related collection | a `DataProviderInterface` service ([data layer](data-layer.md)) |
| **persister** | writes — create / update / delete / relationship mutation | a `DataPersisterInterface` service ([data layer](data-layer.md)) |

`AbstractResource` is pure Symfony-side sugar that bundles the first three from one
declaration. **Nothing is coupled to it.** Which endpoints a type serves falls out
of which capabilities it declares — no provider means no reads, no hydrator/persister
means no writes, a serializer alone means a serialize-only embedded type. The core
library owns the *thesis* (a type composed of independent capabilities); this page
documents the *Symfony wiring* of it. For the model itself, read core's
[capability-composition](https://github.com/haddowg/json-api/blob/main/docs/capability-composition.md).

## The three standalone attributes

A capability that lives apart from a resource is registered by an attribute on its
class. Each attribute is `TARGET_CLASS`, keyed by JSON:API `type`, and autoconfigures
a public service tag so any class in your `src/` that carries it is discovered with no
extra wiring:

| Attribute | Goes on | Tag | Constant |
| --- | --- | --- | --- |
| `#[AsJsonApiSerializer(type, operations, server)]` | a core `SerializerInterface` | `haddowg.json_api.serializer` | `JsonApiBundle::SERIALIZER_TAG` |
| `#[AsJsonApiHydrator(type, server)]` | a core `HydratorInterface` | `haddowg.json_api.hydrator` | `JsonApiBundle::HYDRATOR_TAG` |
| `#[AsJsonApiRelations(type, server)]` | a bundle `RelationsProviderInterface` | `haddowg.json_api.relations` | `JsonApiBundle::RELATIONS_TAG` |

The interfaces these sit on are core's — see core's
[serializers](https://github.com/haddowg/json-api/blob/main/docs/serializers.md),
[hydrators](https://github.com/haddowg/json-api/blob/main/docs/hydrators.md) and
[relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md) for what
each must implement. `RelationsProviderInterface` is the bundle's own one-method seam
(`relations(): list<RelationInterface>`); the `RelationInterface` objects it returns
are core's. The `server` argument assigns the type to one or more named servers (a
single name, a list, or unset for the implicit `default`); see
[multi-server-and-testing](multi-server-and-testing.md).

A **standalone serializer** is the most common: a hand-written serializer for a type
that has no resource and no entity. The example app's `charts` type is exactly this —
the field DSL can't express its verbatim entries list, and there's no `Chart` table
behind it:

```php
// examples/music-catalog-symfony/src/Serializer/ChartSerializer.php
#[AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class ChartSerializer extends AbstractSerializer implements UriTypeAwareInterface
{
    public function uriType(): string
    {
        return 'charts';
    }

    public function getType(mixed $object): string
    {
        return 'charts';
    }

    // … the remaining SerializerInterface methods, hand-written
}
```

A standalone serializer implements `UriTypeAwareInterface` and returns its `uriType()`
because it has no `AbstractResource` to supply the URL path segment otherwise — without
it the segment falls back to `getType()` (see
[custom-serializers-hydrators](custom-serializers-hydrators.md#uritype--a-url-segment-distinct-from-the-type)).

There is **no `charts` resource, no entity, no hydrator** — just this serializer (the
wire shape) plus a small custom [`ChartProvider`](../examples/music-catalog-symfony/src/Provider/ChartProvider.php)
(the data). That alone makes `charts` a read-only, fetchable type. See
[`ChartSerializer`](../examples/music-catalog-symfony/src/Serializer/ChartSerializer.php)
and the equivalent [`CountrySerializer`](../examples/music-catalog-symfony/src/Serializer/CountrySerializer.php)
(a `symfony/intl`-sourced reference list — covered in
[custom-data-providers](custom-data-providers.md)).

A class may carry more than one of these attributes: a single class that implements
both `SerializerInterface` and `HydratorInterface` can bear `#[AsJsonApiSerializer]`
and `#[AsJsonApiHydrator]` together, registering both halves of a resource-less type
in one place.

## The default-operations asymmetry

This is the one footgun to internalise. **A standalone serializer exposes no endpoints
by default; an `AbstractResource` exposes all five.**

| Type kind | Default operations |
| --- | --- |
| `AbstractResource` | all five (`FetchCollection`, `FetchOne`, `Create`, `Update`, `Delete`) |
| standalone `#[AsJsonApiSerializer]` | **none** — serialize-only |

A standalone serializer defaults to serialize-only because the classic use is an
*embedded/reference* type: it renders as primary data, linkage and `included` when it
appears inside another resource, but serves no routes of its own. To give it its own
endpoints you open them explicitly with the `operations` allow-list, as `ChartSerializer`
does above — opening exactly `GET /charts` and `GET /charts/{id}` and nothing else.

The example app makes the asymmetry observable in
[`CapabilityCompositionTest`](../examples/music-catalog-symfony/tests/CapabilityCompositionTest.php):
the `charts` serializer emits exactly the two named fetch routes…

```php
self::assertArrayHasKey('jsonapi.charts.index', $names);   // GET /charts
self::assertArrayHasKey('jsonapi.charts.show', $names);    // GET /charts/{id}
self::assertArrayNotHasKey('jsonapi.charts.create', $names);
```

…while `albums` (an `AbstractResource`) emits all five, including the write routes a
standalone serializer omits:

```php
self::assertArrayHasKey('jsonapi.albums.create', $names);
self::assertArrayHasKey('jsonapi.albums.update', $names);
self::assertArrayHasKey('jsonapi.albums.delete', $names);
```

The allow-list *mechanism* — the `Operation` enum, how a declared case becomes a route,
and what happens to a verb you don't expose (the router 404/405s it, no handler is
reached) — belongs to [routing](routing.md). This page owns only the per-capability
*defaults*; the two pages are cross-linked so they never drift.

## Mix-and-match recipes

Because every capability is optional and independent, the endpoint set is whatever the
capabilities you declare add up to:

| You want | Declare |
| --- | --- |
| a serialize-only embedded/reference type | a serializer alone (no `operations`) |
| a read-only fetchable type | a serializer with `operations: [FetchCollection, FetchOne]` + a provider |
| a write-only ingest endpoint | a hydrator + a persister (no serializer) |
| a fully resource-less CRUD type | serializer + hydrator + relations + provider + persister |

The `charts` type is the second row, lifted straight from the example. The fourth row
needs no resource at all: the serializer and hydrator (two attributes, possibly on one
class), `#[AsJsonApiRelations]` if it has relationships, and a provider/persister pair
([data layer](data-layer.md)) give you the same endpoints an `AbstractResource` would —
assembled from independent parts instead of one declaration.

Capability *override* on a resource is the dual of this: an `AbstractResource` that
keeps the field DSL for most things but swaps one capability for a hand-written class —
`#[AsJsonApiResource(serializer: …)]` or `(hydrator: …)`. That's owned by
[custom-serializers-hydrators](custom-serializers-hydrators.md); the example's `tracks`
(serializer override) and `playlists` (hydrator override) demonstrate it.

## The build-time write-capability guard

You cannot expose a write without something to populate the entity. If a type's
`operations` allow-list includes `Create` or `Update` but no hydrator is registered for
it, the container **fails to compile** — `ResourceLocatorPass::validateWriteCapability()`
throws a `\LogicException` naming the type and the missing hydrator:

```
The JSON:API type "charts" exposes a write operation (Create) but has no hydrator;
register #[AsJsonApiHydrator(type: "charts")] or use an AbstractResource.
```

This is a compile-time fault, not a request-time one — you find it the moment you build
the container, with a fix hint, never as a runtime surprise. (An `AbstractResource`
always carries a hydrator, so it never trips this.)

## How relations differ in wiring

**Standalone relations resolve identically to resource-declared ones** — a resource-less
type that declares `#[AsJsonApiRelations]` gets the same relationship routes and rendering
as one whose relations live on a resource. The [`TypeMetadataResolver`](../src/Server/TypeMetadataResolver.php)
sources a type's relations resource-first, then from the registry, so the two are
interchangeable. That is the takeaway; the wiring underneath differs only because of
**what** the bundle has to store.

The serializer and hydrator attributes record a **class-string** on their tag, and the
bundle resolves a type to its serializer/hydrator through a class-string service locator
that core can read statically — a serializer's `type` is a scalar it can ask for without
instantiating anything. Relations can't work that way: a `RelationInterface` is a
**runtime object**, not a container-dumpable scalar, so it can't be read at compile time.

So relations resolve through a different path. `#[AsJsonApiRelations]` services are
collected into a [`RelationsRegistry`](../src/Server/RelationsRegistry.php) keyed by
**type**, and a type's relations are fetched **lazily** — the registry calls `relations()`
on the provider only when a relationship or related endpoint actually needs them:

```php
// src/Server/RelationsRegistry.php
public function relationsFor(string $type): ?array
{
    if (!$this->providers->has($type)) {
        return null;          // no standalone relations for this type
    }

    $provider = $this->providers->get($type);
    \assert($provider instanceof RelationsProviderInterface);

    return $provider->relations();
}
```

The route loader gates relationship routes on a type *having* relations from either
source. The endpoints themselves — what they serve, the per-relation exposure flags —
are in [relationships](relationships.md).

## Next / see also

- [resources](resources.md) — the `AbstractResource` on-ramp and `#[AsJsonApiResource]`.
- [routing](routing.md) — the `Operation` allow-list mechanism and how a declared case becomes a route.
- [custom-serializers-hydrators](custom-serializers-hydrators.md) — overriding one capability on a resource, and `uriType`.
- [data-layer](data-layer.md) — the provider/persister capabilities that make a type fetchable/writable.
- [relationships](relationships.md) — what the relations capability's endpoints serve.
- Core [capability-composition](https://github.com/haddowg/json-api/blob/main/docs/capability-composition.md) — the model this page wires into Symfony.
