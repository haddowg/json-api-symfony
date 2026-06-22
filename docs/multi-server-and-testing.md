# Multi-server & functional testing

Two cross-cutting topics live on this page. The first is the bundle's
**config-declared multi-server** feature: how you expose one Symfony app
as several JSON:API servers — for API versioning, an admin surface, or a
public/internal split — and how a request resolves to the right one. The second is
the **functional-testing harness** the example app uses and an integrating app
copies: a `KernelTestCase`-based base that drives JSON:API requests through a real
kernel.

If you run a single API you can skip the multi-server half entirely: the top-level
`base_uri`/`version` define an implicit `default` server, so one server is the
zero-config baseline. Multi-server is purely additive on top.

Core owns the `Server` value object each server wraps —
[core `server.md`](https://github.com/haddowg/json-api/blob/main/docs/server.md) and
[core `architecture.md`](https://github.com/haddowg/json-api/blob/main/docs/architecture.md)
describe the multi-`Server` concept this bundle config-drives. Core's runtime
document-assertion helpers —
[core `testing.md`](https://github.com/haddowg/json-api/blob/main/docs/testing.md) —
are usable inside any bundle `KernelTestCase`.

## The one-server baseline

A single-API app needs no `servers:` block. The top-level keys define the implicit
`default` server, and a bare route import mounts it:

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'
    version: '1.1'
```

```yaml
# config/routes/json_api.yaml
json_api_default:
    resource: '.'
    type: jsonapi
```

That is the whole multi-server story for most apps. Everything below adds named
servers on top of this baseline.

## The four moving parts

Multi-server is four small, independent steps. The example app exercises all four
with a single named `admin` server; the witness is
[`MultiServerTest`](../examples/music-catalog-symfony/tests/MultiServerTest.php).

### 1. Declare the extra server (config)

Add named servers under `json_api.servers`. Each carries its own `base_uri` /
`version` and **inherits the top-level value when omitted**:

```yaml
# config/packages/json_api.yaml — examples/music-catalog-symfony
json_api:
    base_uri: 'https://music.example'
    version: '1.1'
    servers:
        admin:
            base_uri: 'https://admin.music.example'
            # version omitted → inherits the top-level '1.1'
```

The full config tree, the surfaced container parameters
(`haddowg_json_api.servers`), and the reserved-name guard live on
[configuration](configuration.md). The one rule worth repeating here: a named
server may **not** be literally `default` (that name is the implicit top-level
server) — declaring it throws a `LogicException` at container build.

### 2. Assign types to servers (the resource attribute)

A type joins a server through the `server:` argument on its
`#[AsJsonApiResource]` — a single name, a list of names, or unset for the implicit
`default`. The same argument is available on the standalone capability attributes
(`#[AsJsonApiSerializer]` / `#[AsJsonApiHydrator]` / `#[AsJsonApiRelations]`). See
[resources](resources.md) for the attribute as a whole.

The example uses all three assignment shapes:

```php
// src/Resource/AlbumResource.php — shared across both servers
#[AsJsonApiResource(entity: Album::class, server: ['default', 'admin'])]
final class AlbumResource extends AbstractResource { /* … */ }
```

```php
// src/Resource/UserResource.php — admin-only
#[AsJsonApiResource(entity: User::class, server: 'admin')]
final class UserResource extends AbstractResource { /* … */ }
```

```php
// src/Resource/ArtistResource.php — default-only (no server: argument)
#[AsJsonApiResource(entity: Artist::class)]
final class ArtistResource extends AbstractResource { /* … */ }
```

A type assigned to a server that isn't declared in `json_api.servers` is a
build-time `LogicException` naming the offending type and listing the declared
servers — the assignment is validated against the config at compile time, never at
request time.

### 3. Mount each server's routes (per-server import)

A `type: jsonapi` import's `resource:` value is **not a path or glob** — it **names
the server** whose routes to emit. The bare `.` (or empty / `default`) import emits
the `default` server; a non-empty, non-`.` string emits that named server. Prefix
and host stay where Symfony users expect them — the import's `prefix()`/`host()`
apply to the emitted paths:

```yaml
# config/routes/json_api.yaml — examples/music-catalog-symfony
json_api_default:
    resource: '.'
    type: jsonapi

json_api_admin:
    resource: admin
    type: jsonapi
    prefix: /admin
```

The route loader namespaces names per server so a type exposed on two servers never
collides: the `default` server keeps the existing unprefixed
`jsonapi.{type}.{action}`, a named server uses `jsonapi.{server}.{type}.{action}`
(e.g. `jsonapi.admin.albums.show`). The full route-name scheme and the import
mechanics are owned by [routing](routing.md).

### 4. Resolution is automatic (`_jsonapi_server`)

Each emitted route carries a `_jsonapi_server` route default — `default` for the
bare import, the named server otherwise. The request lifecycle reads it and resolves
the matching `Server` through `ServerProvider::get($name)` — `ServerProvider` is the
name → `ServerFactory` locator (detailed in the next section):

```php
// src/EventListener/RequestListener.php
$serverName = $request->attributes->get('_jsonapi_server');
$server = $this->servers->get(\is_string($serverName) ? $serverName : null);
```

```php
// src/Server/ServerProvider.php
public function get(?string $name = null): Server
{
    $name ??= self::DEFAULT_SERVER;

    if (!$this->factories->has($name)) {
        throw new \LogicException(\sprintf('No JSON:API server is configured under the name "%s".', $name));
    }

    $factory = $this->factories->get($name);
    \assert($factory instanceof ServerFactory);

    return $factory->create();
}
```

An unknown name is a `LogicException` — a wiring fault, never a request-time `404`.
The kernel listeners are otherwise **unchanged** by multi-server: it falls out of
the existing `_jsonapi_server` seam with no lifecycle change (see
[lifecycle](lifecycle.md)).

## What `ServerProvider` and `ServerFactory` build

`ServerProvider` holds a name → factory service locator and returns the right
`Server` by name. There is one `ServerFactory` per declared server, registered at
the service id `haddowg.json_api.server_factory.<name>` (use
`JsonApiBundle::serverFactoryId($name)`).

Each factory builds the immutable, memoized core `Server` for **its** surface from:

- *that server's* `base_uri` / `version`;
- the PSR-17 response / stream factories;
- **only the resources and standalone pairs assigned to that server** (the
  compiler pass buckets each type per server, so the `admin` factory holds only the
  admin-assigned types);
- the bundle's single `CrudOperationHandler` via `withHandler()`.

It deliberately does **not** install core's PSR-15 `Middleware\*` chain — the bundle
drives the lifecycle from kernel listeners over `Server::dispatch()`, so the only
core wiring it needs is `withHandler()` (core's `dispatch()` throws without a
target). A Doctrine `RelationshipLoadStateInterface` predicate is threaded in when
present, and null otherwise (core then treats every relation as loaded) — see
[doctrine](doctrine.md) for the load-state seam.

Because each server's `Server` carries its own `base_uri`, a type held by two
servers renders **different self-links per server**. The example proves this on the
wire — the shared `albums` type resolves the default `base_uri` on `/albums/1` and
the admin `base_uri` on `/admin/albums/1`:

```php
// tests/MultiServerTest.php
$fromDefault = $this->handle('/albums/1');
$fromAdmin = $this->handle('/admin/albums/1');

self::assertSame(
    'https://music.example/albums/1/relationships/artist',
    $this->relationshipSelf($fromDefault, 'artist'),
);
self::assertSame(
    'https://admin.music.example/albums/1/relationships/artist',
    $this->relationshipSelf($fromAdmin, 'artist'),
);
```

### Where each multi-server concern is documented

| Concern | Owning page |
| --- | --- |
| The `json_api.servers` config map + inheritance + reserved-name guard | [configuration](configuration.md) |
| Per-type `server:` assignment (the attribute argument) | [resources](resources.md) |
| Per-server route import + the per-server route-name scheme | [routing](routing.md) |
| `_jsonapi_server` resolution in the lifecycle | [lifecycle](lifecycle.md) |
| `ServerProvider` / `ServerFactory` (end-to-end, this page) | here |

## Functional testing

The bundle ships a `KernelTestCase`-based harness the example app extends and an
integrating app copies for its own functional tests. The base lives in the bundle's
own test suite at
`haddowg\JsonApiBundle\Tests\Functional\JsonApiFunctionalTestCase` —
`tests/Functional/JsonApiFunctionalTestCase.php`.

That `Tests\` namespace is **autoload-dev only** (the bundle's shipped autoload is
`src/`), so it is **not importable from a consuming app** — copy
`tests/Functional/JsonApiFunctionalTestCase.php` into your own `tests/` directory,
reparent its namespace, and extend your copy. The example app extends the bundle
class directly only because it lives inside the bundle repo (where `tests/` is on
the dev autoload); a standalone app cannot.

### `JsonApiFunctionalTestCase`

It boots the kernel a subclass names, issues JSON:API requests through it, and
decodes documents — and it keeps the global error/exception-handler stack balanced
so PHPUnit's strict mode stays happy (booting and handling install Symfony's
handlers). The methods you use:

| Member | Purpose |
| --- | --- |
| `getKernelClass(): string` | the kernel to boot (override in a subclass) |
| `afterBoot(): void` | hook for data-layer setup once the container is booted (Doctrine schema + seed) |
| `handle(string $path, string $method = 'GET', ?array $body = null): Response` | sets the `application/vnd.api+json` Accept (and Content-Type for a body), issues the request with `catch: true`, returns the HttpFoundation `Response` |
| `decode(Response $response): array` | the response body JSON-decoded to an array |
| `browser(): JsonApiBrowser` | a lazily-built fluent client over the booted kernel — the successor to `handle()`/`decode()` (see [`JsonApiBrowser`](#jsonapibrowser--the-fluent-client) below) |

Two details matter for fidelity to production:

- `handle()` calls `kernel->handle($request, MAIN_REQUEST, catch: true)` — the
  production path — so errors route through `kernel.exception` where the bundle's
  `ExceptionListener` renders JSON:API error documents (see [errors](errors.md)).
  A test asserting a `400`/`404`/`422` body relies on this.
- `setUp()` boots with `['debug' => false]`, so debug-only error meta is redacted
  exactly as in production (see [security](security.md) for the gating).

A body is passed as a PHP array and JSON-encoded for you; `null` sends no body
(GET/DELETE).

### `JsonApiBrowser` — the fluent client

`handle()`/`decode()` give you a `Response` + an array; you then hand-assert the
status, the `Location` header, and `$data['type']`. The bundle ships a fluent
alternative that asserts those **as a unit**: `JsonApiBrowser`
(`haddowg\JsonApiBundle\Testing\JsonApiBrowser`) — a public, supported test utility
that extends Symfony's `KernelBrowser`, knows the JSON:API media type, and bridges
the HttpFoundation response to core's fluent assertion families. Unlike the test
case it **is** on the shipped `src/` autoload, so an integrating app imports it
directly.

`JsonApiFunctionalTestCase` exposes it lazily via `browser(): JsonApiBrowser`
(built once per test, kernel reuse intact). Construct one standalone with `new
JsonApiBrowser($kernel)`.

```php
// A GET fluent chain: status + content type + body asserted together.
$this->browser()
    ->get('/articles/1')
    ->assertFetchedOne()              // 200 + application/vnd.api+json
    ->assertHasType('articles')
    ->assertHasId('1')
    ->assertHasAttribute('title', 'JSON:API in PHP');

// The ?sort order witness — order matters, and is now first-class.
$this->browser()
    ->get('/articles?sort=title')
    ->assertFetchedManyInOrder(['5', '3', '1', '2', '4'], 'articles');

// A POST: the body is JSON-encoded with the write Content-Type for you.
$this->browser()
    ->post('/articles', [
        'data' => ['type' => 'articles', 'attributes' => ['title' => 'New', 'category' => 'news']],
    ])
    ->assertCreated();                // 201 + Location + content type

// Exact-match catches a leaked/extra field; the expected object is derived
// from the entity's own serializer, so you never hand-write the shape.
$article = $repository->find(1);
$this->browser()
    ->get('/articles/1')
    ->assertFetchedOneExact($this->browser()->expectResource($article));

// Stateless Bearer auth (the firewall under test) over chained requests.
$this->browser()
    ->actingAs('admin')               // Authorization: Bearer admin
    ->delete('/articles/1')
    ->assertNoContent();              // 204 + empty body
```

`actingAs(UserInterface|string $user)` authenticates **statelessly** as a seeded
user — the most common API auth scenario. It resolves the user identifier (from a
`UserInterface` or the raw string) and sets `Authorization: Bearer <token>` on every
subsequent request; the firewall's `access_token` authenticator resolves that token
back to the user (in the test apps the token *is* the identifier via a tiny
`AccessTokenHandler`; a real app maps an opaque token to a user). There is no session
and no `loginUser()`. A consumer whose stateless scheme differs overrides **one**
protected seam — `authenticateAs(string $identifier)` (the header) or
`tokenFor(string $identifier)` (the token).

Three browser behaviours mirror `handle()`'s fidelity guarantees, and one is the
headline trap:

- **`disableReboot()` is called in the constructor.** A vanilla `KernelBrowser`
  reboots the kernel between requests, which would wipe an in-memory SQLite seed
  bound to the kernel's connection. The browser keeps the one booted kernel across
  requests — so a **write-then-read in a single test sees the write**, exactly as the
  old `handle()` reusing `static::$kernel` did.
- **The `kernel.exception` path is preserved.** Requests route through
  `kernel->handle(catch: true)`, so a `400`/`404`/`422` comes back as a rendered
  JSON:API **error document**, asserted via `getErrors()`:

  ```php
  $this->browser()->get('/articles/999')->getErrors()
      ->assertStatus(404)
      ->assertContentType()
      ->assertHasError(status: '404');
  ```

- **The PHPUnit strict handler stack stays balanced** — the browser snapshots and
  restores Symfony's error/exception handlers around each request.

`getDocument(): JsonApiDocument` / `getErrors(): JsonApiErrors` expose the underlying
core wrappers (status + headers carried as a `ResponseMeta`), so the full core
assertion vocabulary — `assertFetchedManyExact`, `assertIncludedExactly`,
`assertExactMeta`, `assertNoData`/`assertNoLink`, `assertErrorsExact`, … — is
available beyond the browser-level shorthands.

#### Extending the browser

The class is **non-`final`** and exposes its behaviour as protected, overridable
seams, so a consumer subclasses and customises without copy-paste:

| Seam | Default | Override to … |
| --- | --- | --- |
| `authenticateAs(string $identifier)` | sets `Authorization: Bearer <token>` | authenticate over a different stateless scheme (a custom header, `X-Api-Key`) |
| `tokenFor(string $identifier)` | returns the identifier (the token *is* the user) | mint the opaque token your app's token handler expects |
| `defaultRequestServer(bool $hasBody)` | the `Accept` (+ `Content-Type` on a body) negotiation | negotiate a different media-type profile or add standing headers |
| `documentFor(Response)` / `errorsFor(Response)` | decode `$response->getContent()` + a `ResponseMeta` | wrap a response that needs decoding before the assertions |

#### Using it from a `WebTestCase`

A standard Symfony `WebTestCase` gets a `JsonApiBrowser` from the normal
client-creation flow via the shipped `InteractsWithJsonApi` trait
(`haddowg\JsonApiBundle\Testing\InteractsWithJsonApi`) — `static::createClient()` (or
the `jsonApiClient()` accessor) returns a `JsonApiBrowser`:

```php
use haddowg\JsonApiBundle\Testing\InteractsWithJsonApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlaylistTest extends WebTestCase
{
    use InteractsWithJsonApi;

    public function test_owner_reads_their_playlists(): void
    {
        $client = static::createClient();          // a JsonApiBrowser
        $client->actingAs('ada@example.com')       // Authorization: Bearer …
            ->get('/playlists')
            ->assertFetchedMany();
    }
}
```

The idiomatic swap would be to redefine the `test.client` service's *class* to
`JsonApiBrowser` (its constructor mirrors `KernelBrowser`'s, so it is a drop-in). The
trait instead **overrides `createClient()`** to build the browser straight from the
booted kernel — the bundle's harness boots imperative `MicroKernelTrait` test kernels
with no shared `config/packages/test/` to carry a service override, which makes a
per-kernel service edit fragile. The standard `static::createClient()` ergonomics are
preserved; the trait also snapshots/restores the error/exception handlers (PHPUnit
strict) and boots `debug => false` (production-fidelity error meta, no stdout debug
logs).

### The example app's base case

The example app's suites extend one thin base that names the kernel and seeds the
database in `afterBoot()`:

```php
// examples/music-catalog-symfony/tests/MusicCatalogKernelTestCase.php
abstract class MusicCatalogKernelTestCase extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return MusicCatalogKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        Seed::into($entityManager);
    }
}
```

The in-memory SQLite database lives and dies with the kernel's connection, so the
schema and seed are recreated per test — every suite boots against a fully populated
database.

### The kernel an integrating app copies

The example's
[`MusicCatalogKernel`](../examples/music-catalog-symfony/src/MusicCatalogKernel.php)
is a real Symfony app kernel (`MicroKernelTrait`), not an imperative test kernel: it
loads `config/bundles.php`, `config/packages/*`, and `config/routes/*` from the
project dir. That is the model an integrating app copies for its own tests:

1. register FrameworkBundle + JsonApiBundle (+ DoctrineBundle for the Doctrine path)
   in `config/bundles.php`;
2. configure `json_api`/`doctrine` in `config/packages`;
3. import the route loader (`$routes->import('.', 'jsonapi')`) in `config/routes`;
4. register `src/` as autowired + autoconfigured services — any `AbstractResource`
   is then auto-discovered with no hand-written service definition.

The example's `config/services.yaml` does only that, plus binding the two override
services' scalar constructor args. With autoconfiguration doing the discovery, your
test kernel needs almost no wiring.

### The dual-provider conformance discipline

The bundle's own suite (and the discipline an integrator can adopt) splits tests by
whether a behaviour touches storage:

- **Storage-touching behaviour** (create/update/delete, filter/sort/pagination,
  relationship mutation) is asserted against **both** providers. An abstract
  `*ConformanceTestCase` holds the assertions once; two thin subclasses differ only
  in the kernel they name — an in-memory kernel and a Doctrine-sqlite kernel. So
  `WriteConformanceTestCase` is run by `InMemoryWriteTest` and `DoctrineWriteTest`,
  and a failure on one provider but not the other localizes to that persister's
  execution.
- **Storage-orthogonal concerns** (routing, registration, rendering, multi-server
  route emission) are witnessed on a single in-memory kernel — there is nothing for
  a second provider to disagree about.

The heuristic: if a different `DataProvider`/`DataPersister` could plausibly produce
a different answer, run it against both; otherwise once is enough. Tests are
spec-grouped with `#[Group('spec:…')]` so a CI run can slice by JSON:API spec
chapter.

## Next / see also

- [routing](routing.md) — the per-server route import and the per-server route-name
  scheme.
- [resources](resources.md) — the `#[AsJsonApiResource(server: …)]` assignment
  argument.
- [configuration](configuration.md) — the `json_api.servers` config map and the
  reserved-name guard.
- [lifecycle](lifecycle.md) — how `_jsonapi_server` flows through the request
  listeners.
- [core `testing.md`](https://github.com/haddowg/json-api/blob/main/docs/testing.md)
  — the runtime `JsonApiDocument` / `JsonApiErrors` assertion helpers usable inside a
  bundle `KernelTestCase`.
