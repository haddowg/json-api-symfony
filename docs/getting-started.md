# Your first music-catalog endpoint

This is the end-to-end onboarding walkthrough, Symfony edition. By the end you will
have a spec-compliant `albums` endpoint — fetch *and* create — running over Doctrine
in a real Symfony application, with **no controller, no operation handler, and no
serializer written by hand**. Every snippet here is lifted from the CI-run
[`examples/music-catalog-symfony`](../examples/music-catalog-symfony) app, so what
you read is what runs.

This page assumes the bundle is already installed and registered — if not, do
[install](install.md) first (it covers installation and bundle registration). It also
assumes you have read core's
[getting-started](https://github.com/haddowg/json-api/blob/main/docs/getting-started.md)
for the JSON:API mental model; this page is the *Symfony* counterpart of that page.

## The split: what you provide, what the bundle provides

A working endpoint in this bundle is a short list of things **you** declare and a
much longer list of machinery the **bundle** supplies for you.

You provide:

- a Doctrine entity (`Album`) — your storage model,
- an `AbstractResource` registered as a service, describing the JSON:API shape,
- the `#[AsJsonApiResource(entity: Album::class)]` attribute that maps the type to
  the entity,
- a one-line route import,
- a two-key configuration block.

The bundle provides — discovered and wired automatically:

- **discovery** — autoconfiguration tags any `AbstractResource` service, so it just
  works once registered ([resources](resources.md)),
- **routing** — a route loader emits the full JSON:API endpoint set per type
  ([routing](routing.md)),
- **the lifecycle** — three kernel listeners negotiate, dispatch, and render the
  request, so the profiler, firewall, and logging all wrap it like any Symfony
  endpoint ([lifecycle](lifecycle.md)),
- **error rendering** — every failure on a JSON:API route becomes a spec document
  ([errors](errors.md)),
- **the Doctrine data layer** — a reference read/write path over your entities, with
  zero configuration ([doctrine](doctrine.md)).

You never touch a controller, a handler, or a serializer. The rest of this page
builds the `albums` endpoint one piece at a time.

## Step 1 — the Doctrine entity

A plain Doctrine entity, mapped however you like. The example's
[`Album`](../examples/music-catalog-symfony/src/Entity/Album.php) is a normal
attribute-mapped class — nothing about it is JSON:API-aware:

```php
#[ORM\Entity]
#[ORM\Table(name: 'album')]
class Album
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $releasedAt = null,
        #[ORM\Column(type: 'boolean')]
        public bool $explicit = false,
        // …
    ) {}
}
```

The id is a **database-assigned auto-increment integer** — the bundle's
*store-provided* default id strategy (a create sets nothing, the database assigns
the id, the `201` reads it back). Other strategies — a client-supplied id, an
app-minted UUID/ULID, an opaque encoded id — are selected on the `Id` field; see
[resources](resources.md#sourcing-the-resource-id). The entity stays a storage concern. The JSON:API shape lives entirely in the
resource, next.

## Step 2 — the resource

A resource extends core's `AbstractResource`. Its static `$type` is the JSON:API
type, and `fields()` declares the shape — **one declaration drives both serialize
and hydrate**. A minimal `albums` resource is just an `Id` and a couple of fields:

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Album::class)]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            DateTime::make('releasedAt')->sortable(),
            Boolean::make('explicit'),
        ];
    }
}
```

What goes inside `fields()` — every field type, the `Id`, relations, and the
constraints those `->required()->maxLength()` calls declare — is **core's** vocabulary.
It is not re-explained here. Reach for the core pages:
[resources](https://github.com/haddowg/json-api/blob/main/docs/resources.md),
[fields](https://github.com/haddowg/json-api/blob/main/docs/fields.md),
[field-types](https://github.com/haddowg/json-api/blob/main/docs/field-types.md),
[ids](https://github.com/haddowg/json-api/blob/main/docs/ids.md).

The full example resource layers more on top — a `Map`, a directional
`CompareField`, default includes, relations — see
[`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php).
The minimal version above is enough to get endpoints.

> **A to-many relation is lazy by default — "where's my `data`?"** When you add a
> relation (`HasMany::make('tracks', 'tracks')`), the album document renders the
> relationship's `self`/`related` **links** but **no `data` linkage** until the client
> asks for it — via `?include=tracks` or the `GET /albums/{id}/relationships/tracks`
> endpoint. This is deliberate: emitting linkage for a lazy to-many would force a query
> per parent (an N+1 across a collection). A to-one whose key sits on the owner (e.g.
> `BelongsTo`) is eager by default — its identifier is free. To make a to-many emit its
> linkage `data` eagerly, opt in with `->withData()`:
> `HasMany::make('tracks', 'tracks')->withData()`. Relations, the lazy/eager defaults,
> and `?include` are owned by [relationships](relationships.md).

> An attribute can also be **flattened** from a related model
> (`Str::make('authorName')->on('author')`) or **computed** read-only
> (`->computedUsing($closure)`). The flattened relation is eager-loaded by the bundle so
> the read does not N+1 — see
> [relationships → flattened attributes](relationships.md#flattened-on-attributes-and-eager-loading).

## Step 3 — register it as a service

This is where the Symfony integration begins. **Autoconfiguration tags any service
whose class extends `AbstractResource`** as a JSON:API resource — there is no manual
tagging and no central registry to edit. In practice you register `src/` as autowired
+ autoconfigured services once and every resource is discovered:

```yaml
# config/services.yaml
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

(From the example's
[`services.yaml`](../examples/music-catalog-symfony/config/services.yaml) — the
entities and the kernel are excluded because they are not services.)

Because a resource is a real service, it can have constructor dependencies and is
resolved lazily through the container. The one thing the bundle needs to know that
the class can't tell it is *which entity backs this type* — that is the
`#[AsJsonApiResource(entity: Album::class)]` attribute on the class. The attribute is
**optional metadata**: discovery happens without it, but `entity:` is what wires the
type to the reference [Doctrine](doctrine.md) provider/persister. The attribute also
carries per-type overrides (custom serializer/hydrator, the operation allow-list,
server assignment) — see [resources](resources.md) for every argument.

## Step 4 — configure and import the routes

Two config touches. First, the bundle configuration — at minimum a `base_uri`
(it seeds the links the documents render):

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'
```

(`version:` defaults to `'1.1'`, so set it only for a non-default JSON:API version —
the [example's `json_api.yaml`](../examples/music-catalog-symfony/config/packages/json_api.yaml)
pins it just as the explicit-version witness; most apps omit it.)

Second — and this is the step many first-time users miss — **routes are not
auto-mounted**. You import the bundle's custom route type, which emits one literal
route per type and operation from the discovered resources. The example's
[`json_api.yaml` route file](../examples/music-catalog-symfony/config/routes/json_api.yaml):

```yaml
# config/routes/json_api.yaml
json_api_default:
    resource: '.'
    type: jsonapi
```

Or, in PHP routing config, `$routes->import('.', 'jsonapi')`. The `resource:`
argument **names a server**, not a path or glob — types come from the compiled
resource descriptors. The bare `.` (equivalently `resource: 'default'`, the
self-describing form) selects the implicit `default` server; naming other servers
only matters once you run more than one — see [routing](routing.md) and
[configuration](configuration.md).

There is no `Configuration.php` or `Extension` class to write — the config tree is
tiny and declared inline by the bundle. The full reference (every key, the container
parameters, the optional-dependency matrix) is on [configuration](configuration.md).

That is the whole setup. A registered resource defaults to **all five CRUD
operations**, so `albums` now serves `GET`/`POST` on the collection and
`GET`/`PATCH`/`DELETE` on a member.

## Step 5 — three worked outcomes

Here are three requests against the endpoint you just built, each one a real
assertion from
[`GettingStartedTest`](../examples/music-catalog-symfony/tests/GettingStartedTest.php).
Every request carries the JSON:API media type `application/vnd.api+json` — the bundle
adds no default `Accept`/`Content-Type` (core enforces the media type; see
[content-negotiation](https://github.com/haddowg/json-api/blob/main/docs/content-negotiation.md)).

The endpoint is a normal HTTP route, so the simplest way to see it respond is `curl`:

```console
$ curl -H 'Accept: application/vnd.api+json' https://music.example/albums
{"jsonapi":{"version":"1.1"},"data":[{"type":"albums","id":"…","attributes":{"title":"…"}}]}
```

> **Run the example live.** The
> [`examples/music-catalog-symfony`](../examples/music-catalog-symfony) app ships a
> [FrankenPHP](https://frankenphp.dev/) container — `docker compose up` from
> `examples/music-catalog-symfony/` boots the whole catalogue (seeded SQLite) on
> **http://localhost:8080**, so you can `curl` these endpoints for real:
>
> ```bash
> docker compose up   # from examples/music-catalog-symfony/
> curl -H 'Accept: application/vnd.api+json' http://localhost:8080/albums
> ```

The outcomes below express that same behaviour as CI assertions. The `handle()` and
`decode()` calls are not Symfony built-ins — they are helpers on the bundle's
functional-test harness (`handle()` issues a request through the booted kernel with
the media type set; `decode()` JSON-decodes the response body), documented under
[`JsonApiFunctionalTestCase`](multi-server-and-testing.md#jsonapifunctionaltestcase).

### `GET /albums` → `200` collection

```php
$response = $this->handle('/albums');

self::assertSame(200, $response->getStatusCode());
self::assertStringContainsString(
    'application/vnd.api+json',
    (string) $response->headers->get('Content-Type'),
);

$document = $this->decode($response);
self::assertSame(['version' => '1.1'], $document['jsonapi']);
self::assertSame('albums', $document['data'][0]['type']);
self::assertArrayHasKey('title', $document['data'][0]['attributes']);
```

A spec-compliant collection document — `jsonapi.version`, a `data` array of resource
objects, attributes per the field declarations.

### `POST /albums` → `201` + `Location`

A create. The request body is a JSON:API document; the response is `201` with a
`Location` header built from the resource type and the resulting id, plus the created
resource in the body. Any registered type creates the same way — the example shows it
on a sibling `playlists` type purely so the create carries a client-supplied id you
can predict in the `Location` assertion below; `albums` behaves identically. Here is
the assertion verbatim:

```php
$response = $this->handle('/playlists', 'POST', [
    'data' => [
        'type' => 'playlists',
        'id' => '00000000-0000-4000-8000-00000000abcd',
        'attributes' => ['title' => 'Late Night', 'public' => true],
    ],
]);

self::assertSame(201, $response->getStatusCode());
self::assertSame(
    'https://music.example/playlists/00000000-0000-4000-8000-00000000abcd',
    $response->headers->get('Location'),
);
self::assertSame('playlists', $this->decode($response)['data']['type']);
```

The same `fields()` that rendered the read now **hydrated** the write, and the Doctrine
persister committed it — a follow-up `GET` returns the persisted record.

> **Constraint enforcement is opt-in.** The `->required()->maxLength()` calls you
> declared in `fields()` are only *enforced* once the optional
> [`symfony/validator` bridge](validation.md) is installed; in a fresh install the
> body is hydrated but **not** validated. Wire it before relying on `422` responses.

### `GET /albums/999` → `404`

The show route exists, so the request reaches the handler; the provider's null fetch
becomes a **route-scoped JSON:API `404` document** — not a bare Symfony 404 page:

```php
$response = $this->handle('/albums/999');

self::assertSame(404, $response->getStatusCode());
self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

$first = $this->decode($response)['errors'][0];
self::assertSame('404', $first['status']);
self::assertSame('RESOURCE_NOT_FOUND', $first['code']);
```

## What just happened

Each outcome was produced by a stage you never wrote:

| Outcome | Produced by |
| --- | --- |
| `GET /albums` → `200` | The [route loader](routing.md) matched `jsonapi.albums.index`; the [lifecycle](lifecycle.md) listeners negotiated and dispatched; the [Doctrine](doctrine.md) provider's `fetchCollection` ran a `QueryBuilder`; the view listener rendered the [response VO](https://github.com/haddowg/json-api/blob/main/docs/responses.md). |
| `POST /albums` → `201` | The lifecycle parsed and negotiated the write; core hydrated the body through `fields()`; the [Doctrine persister](doctrine.md) persisted and flushed; the handler rendered `201` + `Location`. |
| `GET /albums/999` → `404` | The provider's `fetchOne` returned null; the [error listener](errors.md) — route-scoped to JSON:API routes only — rendered the spec error document. |

The pieces glue together through Symfony's own machinery: discovery is
autoconfiguration, dispatch is kernel listeners, errors are a `kernel.exception`
listener. Nothing here is a black box — every stage has its own page.

## Where to go next

You have a working type. To go deeper, in rough reading order:

- [resources](resources.md) — every `#[AsJsonApiResource]` argument, the discovery
  model, and `$type` vs `$uriType`.
- [routing](routing.md) — the full generated route set, the operation allow-list, and
  trimming endpoints.
- [lifecycle](lifecycle.md) — the three-listener flow and content negotiation.
- [doctrine](doctrine.md) — the reference data layer: filters, sorts, related
  collections, the load-state seam.
- [relationships](relationships.md) — declare relations, render linkage and `links`,
  read/mutate relationship endpoints, `?include`, `?withCount`, pivot data, and the
  relationship-queries profile.
- [validation](validation.md) — wire `symfony/validator` so your declared constraints
  are actually enforced (writes run **unvalidated** until you do).
- [configuration](configuration.md) — the config reference and the optional-dependency
  matrix. Note one default a first request can trip on: **strict query parameters** is
  on by default, so an *unrecognized* query family (`?filtr=…`, a typo) is a `400`
  rather than a silent `200` — see
  [`strict_query_parameters`](configuration.md#strict_query_parameters).

For contrast with the framework-free path, core's
[getting-started](https://github.com/haddowg/json-api/blob/main/docs/getting-started.md)
builds the same type by hand on a core `Server` — the same `AbstractResource`, but
hand-registered and hand-dispatched. This bundle is that, automated.
