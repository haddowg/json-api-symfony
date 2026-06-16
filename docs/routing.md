# The route loader and operation-gated routes

The bundle ships a custom Symfony route loader that turns your discovered JSON:API
types into concrete routes. You import one route type; the loader emits exactly the
endpoints each type declares. There is no catch-all controller and no path parsing —
one literal path per type per operation, so the router itself `404`s an unknown type.

This page owns the route import, the generated route set, the operation allow-list
that gates which routes exist, the per-server route-name scheme, and the
`TargetResolver` seam for hand-written routes. The shape of an `Operation\Target`
and the verb×target dispatch it feeds are core's —
see [operations](https://github.com/haddowg/json-api/blob/main/docs/operations.md).

## The one required step: import the route type

Registering the bundle and your resources does **not** mount any endpoints. Routes
are a separate, explicit step (this is the most common "why are there no endpoints?"
surprise — see [install](install.md)). You import the bundle's custom route type,
`jsonapi`:

```yaml
# config/routes/json_api.yaml
json_api_default:
    resource: '.'
    type: jsonapi
```

In PHP config that is `$routes->import('.', 'jsonapi')`. The loader's type selector
is the constant `JsonApiRouteLoader::ROUTE_TYPE === 'jsonapi'`.

The `resource:` argument is **not a path or glob** — the types come from the
compiled descriptors built at container-build time by `ResourceLocatorPass`, not by
scanning a directory. But the argument is not ignored either: it **names the
server** (bundle ADR 0034). The bare `.` (or empty, or the literal `default`) import
emits the **`default`** server's routes; any other non-empty string names a
configured server and emits that server's routes:

```yaml
# config/routes/json_api.yaml — the example app mounts a second, named server
json_api_admin:
    resource: admin
    type: jsonapi
    prefix: /admin
```

Source: [`config/routes/json_api.yaml`](../examples/music-catalog-symfony/config/routes/json_api.yaml).
The `admin` import emits only the `admin` server's routes. Prefix, host and
condition stay in your routing config where Symfony users expect them — Symfony
applies the import's `prefix('/admin')` to every emitted path. An unknown or empty
server emits nothing. Server *configuration* (the `servers:` map) lives on
[configuration](configuration.md); *assignment* (which types join which server) is
the `server:` argument on the resource attribute (see [resources](resources.md)); the
end-to-end per-server resolution is on [multi-server-and-testing](multi-server-and-testing.md).

## The generated route set

For each type the loader emits one route per **declared operation**, plus the
relationship routes for any type that declares relations. `{seg}` below is the
type's `uriType` — the URL path segment, which may differ from the JSON:API type
(`uriType` is owned by [custom-serializers-hydrators](custom-serializers-hydrators.md)).
Route **names** key on the JSON:API type; **paths** use `uriType`.

| Operation | Method + path | Default route name |
| --- | --- | --- |
| `FetchCollection` | `GET /{seg}` | `jsonapi.{type}.index` |
| `Create` | `POST /{seg}` | `jsonapi.{type}.create` |
| `FetchOne` | `GET /{seg}/{id}` | `jsonapi.{type}.show` |
| `Update` | `PATCH /{seg}/{id}` | `jsonapi.{type}.update` |
| `Delete` | `DELETE /{seg}/{id}` | `jsonapi.{type}.delete` |

For any type with relations (a full resource, which always bundles relations, or a
resource-less type declaring `#[AsJsonApiRelations]`) the loader additionally emits:

| Endpoint | Method + path | Default route name |
| --- | --- | --- |
| Linkage read | `GET /{seg}/{id}/relationships/{relationship}` | `jsonapi.{type}.relationship.show` |
| Replace linkage | `PATCH /{seg}/{id}/relationships/{relationship}` | `jsonapi.{type}.relationship.update` |
| Add to linkage | `POST /{seg}/{id}/relationships/{relationship}` | `jsonapi.{type}.relationship.add` |
| Remove from linkage | `DELETE /{seg}/{id}/relationships/{relationship}` | `jsonapi.{type}.relationship.remove` |
| Related resources | `GET /{seg}/{id}/{relationship}` | `jsonapi.{type}.related.show` |

The four-segment linkage path (`…/relationships/{relationship}`) and the
three-segment related path (`…/{relationship}`) differ in segment count, so they
never shadow one another — nor the two-segment `/{seg}/{id}` resource route. The
loader registers the linkage routes **first**, so the literal `relationships`
segment is never captured as a `{relationship}` name. What these endpoints serve is
core's —
see [related-endpoints](https://github.com/haddowg/json-api/blob/main/docs/related-endpoints.md)
and [relationship-mutation](https://github.com/haddowg/json-api/blob/main/docs/relationship-mutation.md);
the bundle-side per-relation exposure gates are on [relationships](relationships.md).

> Relationship and related routes are **not** gated by the `Operation` allow-list
> below. Whether a given relation exposes a related or relationship endpoint is
> governed per-relation (`withoutRelatedEndpoint()` / `withoutRelationshipEndpoint()`),
> enforced handler-side while the routes stay parametric — see [relationships](relationships.md).

### Router-native, no catch-all

Because the loader emits one literal path per type, the router resolves a known type
to its route and `404`s (or `405`s on a wrong method) an unknown one on its own,
before any bundle code runs. There is no `/{type}` catch-all and no path parsing.
The practical upshot: per-route security, firewall maps and conditions work exactly
as they do for any other Symfony route, because every JSON:API endpoint is a real,
named route in the collection.

## The operation allow-list

Which routes a type serves is gated by the public `Operation` enum
([`src/Operation/Operation.php`](../src/Operation/Operation.php)) — five cases, each
mapping to one route:

```php
enum Operation: string
{
    case FetchCollection = 'FetchCollection';
    case FetchOne = 'FetchOne';
    case Create = 'Create';
    case Update = 'Update';
    case Delete = 'Delete';
}
```

Each case value equals its name so a descriptor survives container dumping as a
plain string. You set the allow-list with the `operations:` argument on the
resource or serializer attribute. A type that omits an operation simply never gets
that route — the verb is **unrouted** (the router `404`s/`405`s natively; no handler
is reached and no error document is produced by the bundle for it).

The defaults are asymmetric, and this is a deliberate footgun worth internalising:

| Type kind | Default operations |
| --- | --- |
| `AbstractResource` (`#[AsJsonApiResource]`) | all five |
| Standalone `#[AsJsonApiSerializer]` | **none** (serialize-only) until `operations:` opens them |

A registered resource gets the full CRUD set with no `operations:` argument. A
standalone serializer — the classic embedded/reference type — exposes **no**
endpoints until you list some. The example app's read-only `charts` type opens
exactly two:

```php
#[AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class ChartSerializer extends AbstractSerializer implements UriTypeAwareInterface
{
    // …
}
```

Source: [`ChartSerializer`](../examples/music-catalog-symfony/src/Serializer/ChartSerializer.php)
(and likewise [`CountrySerializer`](../examples/music-catalog-symfony/src/Serializer/CountrySerializer.php)).
That yields only `GET /charts` and `GET /charts/{id}`; there is no `charts` resource,
entity or hydrator. The allow-list *mechanism* (how a verb becomes a route, the
unrouted-verb `404`/`405`) is this page; the per-capability *defaults* and the full
capability-composition story are on
[capability-composition](capability-composition.md) — cross-linked both ways so the
two never drift.

> The `operations:` list round-trips through the container as comma-joined case-value
> strings (objects are not dumpable as a compiled argument). An unrecognised value is
> silently dropped rather than failing the build.

## The per-server route-name scheme

When you run more than one server, the same type may be exposed on several of them,
so route names must not collide. The scheme (bundle ADR 0034):

- The **`default`** server keeps the unprefixed names from the tables above —
  `jsonapi.{type}.{action}`.
- A **named** server namespaces them — `jsonapi.{server}.{type}.{action}` (e.g.
  `jsonapi.admin.albums.show`).

The example app's `albums` type is `server: ['default', 'admin']`, so it mounts on
both surfaces under distinct names with no collision:

```php
// MultiServerTest — the same type, distinct route names per server
self::assertContains('/albums/{id}', $paths);
self::assertContains('/admin/albums/{id}', $paths);
self::assertArrayHasKey('jsonapi.albums.show', $names);
self::assertArrayHasKey('jsonapi.admin.albums.show', $names);
```

Source: [`MultiServerTest`](../examples/music-catalog-symfony/tests/MultiServerTest.php).
An admin-only type (`server: 'admin'`) gets only the namespaced name
(`jsonapi.admin.users.show`); a default-only type (no `server:` argument) gets only
the unprefixed one (`jsonapi.artists.show`).

## The route-defaults contract

You can skip this table unless you hand-write a route (see the `TargetResolver`
seam below); the standard import sets all of these for you.

Every emitted route carries a fixed set of route defaults that the lifecycle reads.
If you hand-write a route (see the seam below), you must reproduce all of these or
the lifecycle will not engage:

| Default | Value | Read by |
| --- | --- | --- |
| `_controller` | `JsonApiController::class` | HttpKernel |
| `_jsonapi_type` | the JSON:API type | `TargetResolver` |
| `_jsonapi_server` | the import's server name (`default` for the bare import) | `RequestListener` → `ServerProvider::get()` |
| `_jsonapi` | `true` (the `ExceptionListener::ROUTE_MARKER`) | `ExceptionListener` ([errors](errors.md)) |

Relationship and related routes add one more,
`_jsonapi_relationship_endpoint` (`true` for the four-segment linkage path, `false`
for the three-segment related path), which `TargetResolver` reads to build the
relationship-aware target. `_jsonapi_server` is how a per-server route reaches its
own `Server`: `RequestListener` resolves it via
`ServerProvider::get($request->attributes->get('_jsonapi_server'))` — `ServerProvider`
is the runtime resolver that maps a server name to its `ServerFactory`'s `Server`
(see [multi-server-and-testing](multi-server-and-testing.md#what-serverprovider-and-serverfactory-build)) —
so each route renders links against its own `base_uri` (see
[server](https://github.com/haddowg/json-api/blob/main/docs/server.md) for what a
`Server` is, and [lifecycle](lifecycle.md) for the dispatch).

## The explicit-route seam: `TargetResolver`

If you would rather declare some routes by hand than use the import — say you want a
non-standard path or to wire one endpoint into an existing controller — the public
mapping primitive is `TargetResolver::resolveFromRequest(Request): ?Target`
([`src/Operation/TargetResolver.php`](../src/Operation/TargetResolver.php)). It is a
**pure mapper**: no container, no I/O. It reads the route attributes (`_jsonapi_type`,
the optional `{id}` and `{relationship}` path parameters, and
`_jsonapi_relationship_endpoint`) and returns a core `Target`, or `null` when the
route carries no `_jsonapi_type`.

> **Calling `TargetResolver` alone is not enough.** It only builds the `Target`. A
> hand-written route must *also* set every route default in the contract above
> (`_controller`, `_jsonapi_type`, `_jsonapi_server`, `_jsonapi`, plus
> `_jsonapi_relationship_endpoint` for relationship routes) and resolve to a
> controller that returns the stashed response value object — otherwise the kernel
> listeners never run and you get no JSON:API response. For the standard endpoint set,
> use the route import; reach for `TargetResolver` only when you genuinely need a
> bespoke route.

## Next / see also

- [lifecycle](lifecycle.md) — how a matched route becomes a response (the kernel
  listeners, `Server::dispatch()`, content negotiation).
- [capability-composition](capability-composition.md) — the per-capability operation
  defaults and the standalone-registration model behind the allow-list.
- [resources](resources.md) — `#[AsJsonApiResource]` and the `server:` / `operations:`
  arguments.
- [relationships](relationships.md) — the per-relation exposure gates on the
  relationship/related routes.
- [multi-server-and-testing](multi-server-and-testing.md) — end-to-end per-server
  resolution and how to test routes.
- Core: [operations](https://github.com/haddowg/json-api/blob/main/docs/operations.md),
  [related-endpoints](https://github.com/haddowg/json-api/blob/main/docs/related-endpoints.md),
  [relationship-mutation](https://github.com/haddowg/json-api/blob/main/docs/relationship-mutation.md),
  [server](https://github.com/haddowg/json-api/blob/main/docs/server.md).
