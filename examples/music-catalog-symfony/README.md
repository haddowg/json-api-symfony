# Music Catalog — `haddowg/json-api-symfony` example app

A complete JSON:API 1.1 service built on **`haddowg/json-api-symfony`**, served
from a real **Symfony + Doctrine** application over an in-memory SQLite database.
It is the Symfony+Doctrine twin of core's in-memory
[`examples/music-catalog/`](https://github.com/haddowg/json-api/tree/main/examples/music-catalog)
— **same eight domains, same theme** — so you can hold the two example apps side
by side and see exactly what the framework integration adds.

This app is the **single source of truth** for the bundle docs: every doc snippet
is extracted from a CI-run `KernelTestCase`, so the docs cannot drift.

> This example is **not published to Packagist**. Its `composer.json` is a
> documentation artifact showing what a real integrating app requires (the bundle,
> `symfony/*` including `symfony/doctrine-bridge` for `UniqueEntity`, `symfony/intl`
> for the reference-data resource, and `symfony/security-bundle` for the
> declarative-authorization witness, and `doctrine/*`). It runs as part of the
> bundle's own test suite via the bundle's autoload-dev + PHPUnit wiring — you do
> not `composer install` it standalone.

## The domains

Most are Doctrine-entity-backed `AbstractResource` types; one (`Chart`) is a
store-backed serialize-only type with no entity and no resource, and `countries` is a
reference-data type sourced from `symfony/intl`.

| Type | Backing | Highlights |
| --- | --- | --- |
| `artists` | `Artist` entity | singular `filter[slug]`, computed `trackCount`, `hasMany albums` (`cannotBeIncluded` — include safeguard A) |
| `albums` | `Album` entity | multi-server (default + `admin`), directional `CompareField`, `Map releaseInfo` (JSON column), default-include `artist`, `WhereHas tracks` |
| `tracks` | `Track` entity | **serializer override** (`TrackSerializer`), `storedAs` rename, `ArrayList genres`, `like` filter, `belongsToMany playlists` (`cannotReplace`) |
| `playlists` | `Playlist` entity | **hydrator override** (`PlaylistHydrator`), **UUID id** (`uuid()->generated()`), derived `slug`, **lifecycle hooks** (`beforeCreate` stamp + `beforeDelete` 409 guard), **authorization** (`securityDelete` admin-only + `securityUpdate` owner Voter) |
| `users` | `User` entity | **admin-server-only**, `UniqueEntity` on `email`, write-only `password`, validation-composition trio, `getAllowedIncludePaths` whitelist (include safeguard C) |
| `favorites` | `Favorite` entity | **polymorphic to-one** `MorphTo favoritable` (Track\|Album\|Artist) |
| `libraries` | `Library` entity | **polymorphic to-many** `MorphToMany items` (custom provider) |
| `genres` | `Genre` entity | **client-supplied natural-key id** (`requireClientId()->pattern(slug)`) |
| `devices` | `Device` entity | **app-generated ULID id** (`ulid()->generated()`) |
| `products` | `Product` entity | **encoded store-provided id** (`encodeUsing` over a DB-assigned int), self-referential `parent` |
| `charts` | store-backed | standalone serializer, read-only (no entity, no resource) |
| `countries` | `symfony/intl` | reference-data provider, read-only (ISO alpha-2 id) |

## The id-strategy matrix

The id a JSON:API resource exposes is configured on its `Id` field along **two
orthogonal axes** — who may supply a client `data.id` on create, and what fills the
id when the client supplies none — plus an optional **wire-format** declaration
(`uuid()`/`ulid()`/`numeric()`/`pattern()`) that pins the route `{id}` shape and is
validated on the wire (both a client-supplied id and each relationship linkage id).
Incrementing **store-provided** ids are the example's norm (the most common real
pattern); the rest differ deliberately to demonstrate the other behaviours:

| Type | Id field | Strategy |
| --- | --- | --- |
| `artists`, `albums`, `tracks`, `users`, `favorites`, `libraries` | `Id::make()` | **store-provided** — a DB-assigned auto-increment integer (the default); a create sets nothing, the database assigns the id, the `201` reads it back |
| `products` | `Id::make()->encodeUsing(...)->matchAs(...)` | **encoded store-provided** — the DB-assigned integer is never exposed; the wire id (and URL) is an opaque `prod-…` token the codec encodes/decodes |
| `playlists` | `Id::make()->uuid()->generated()` | **app-generated UUID** — the app mints a v4 UUID when a create omits the id (the custom hydrator also accepts a well-formed client UUID) |
| `devices` | `Id::make()->ulid()->generated()` | **app-generated ULID** — core mints a lexicographically sortable Crockford-base32 ULID when a create omits the id |
| `genres` | `Id::make()->requireClientId()->pattern(...)` | **client-supplied natural key** — a create MUST carry `data.id` (absent → `403`); the supplied slug, validated against the format, is the primary key |

The two client-id axes (independent of the fallback) read:

- `allowClientId()` — a client `data.id` is **optional** (used if supplied, validated
  against the format), else the fallback fills it;
- `requireClientId()` — a client `data.id` is **mandatory** (`403` when absent);
- *(default)* a client `data.id` is **forbidden** (`403` when supplied).

The fallback when no client id is supplied:

- *(default)* **store-provided** — core sets nothing; the persister/DB assigns the id;
- `generated()` — core generates from the declared format (`uuid()` → v4 UUID,
  `ulid()` → ULID; a non-self-generating format is a config error);
- `generateUsing(\Closure $fn)` — `$fn()` returns the generated storage key.

## Lifecycle hooks

Every CRUD operation fires a per-operation lifecycle event the bundle ships
(`serving` → `BeforeSave` → `BeforeCreate`/`BeforeUpdate`/`BeforeDelete` → commit →
`AfterCreate`/… → `AfterSave`, plus the relationship-mutation and read hooks). The
bundle exposes the seam through **two mechanisms** — overridable per-type resource
hook methods, and plain Symfony event subscribers (the methods are sugar over the
events, routed by a built-in subscriber). This app demonstrates both:

- **Per-type resource methods** — `PlaylistResource` implements
  `ResourceLifecycleHooksInterface` (`use ResourceLifecycleHooksTrait;` for the
  no-op defaults) and overrides just two hooks:
  - `beforeCreate(object $entity, HookContext $ctx)` **mutates** the entity (stamps
    an `externalId` when the create omits one). A before hook runs with the entity
    mutable and *before* the persister flush, so the change is durably persisted — a
    follow-up read returns it.
  - `beforeDelete(object $entity, HookContext $ctx)` is a **guard** that throws a
    `409` (a `JsonApiExceptionInterface` the route-scoped `ExceptionListener`
    renders) when the playlist still references tracks. A before hook that throws
    aborts the operation — nothing is deleted. An empty playlist deletes normally.

- **A cross-cutting event subscriber** — `AuditLogSubscriber` is a plain
  `EventSubscriberInterface` (autoconfigured, no bundle wiring) that listens to
  events fired for **every** type, so one concern spans the whole API from one
  place:
  - on the **`serving`** event (fired once per request, before the operation) it
    aborts every mutating request with a `403` when the request carries
    `X-Read-Only: on` — a deploy flag that freezes writes API-wide;
  - on `AfterSave` (every create *and* update) and `AfterDelete` it appends an audit
    line to the public `AuditLog` service. After hooks fire post-commit, so an entry
    means the write durably happened; the wire id is captured in a `BeforeDelete`
    handler (the entity is still live there) and the serializer is resolved on the
    server the operation dispatched on (so the admin-only `users` type audits
    correctly under multi-server).

A before hook (resource method or subscriber) aborts by throwing a
`JsonApiExceptionInterface` (`HookAbortException` here, carrying `403`/`409`/`422`);
an after hook may replace the response value object by returning a new one
(custom-action shaping). `tests/LifecycleHooksTest.php` exercises both mechanisms
end to end. See [`docs/lifecycle-hooks.md`](../../docs/lifecycle-hooks.md) for the
full hook set and semantics.

## Authorization

The bundle ships an optional **declarative authorization** layer (built on the
lifecycle hooks): a resource declares Symfony Security
[expressions](https://symfony.com/doc/current/security/expressions.html) on its
`#[AsJsonApiResource]` attribute, and the bundle evaluates them at the right hook —
denying with a JSON:API `403` (or `401` when unauthenticated) **before** any
persistence. `PlaylistResource` carries two:

- `securityDelete: "is_granted('ROLE_ADMIN')"` — a **role gate**: only an admin may
  delete a playlist.
- `securityUpdate: "is_granted('EDIT', object)"` — an **ownership gate**: `object` is
  the loaded playlist, and `is_granted('EDIT', …)` delegates to
  `Security/PlaylistOwnerVoter` (an ordinary Symfony Voter), which grants `EDIT` only
  when the authenticated user's identifier equals the playlist owner's email — so only
  a playlist's owner may update it.

Create and read carry no expression, so they stay ungated (anyone may create or read).
A type that declares no `security` — every other type here — is never affected.

The layer needs a firewall: `config/packages/security.yaml` wires the smallest
witness — an in-memory user provider (`admin`; `ada@example.com`, the seeded
playlist's owner; and `mallory@example.com`, a non-owner) behind a stateless
HTTP-Basic firewall. The firewall is optional (no `access_control`), so an
unauthenticated request still reaches the controller and the security expressions are
what gate it. `tests/AuthorizationTest.php` proves the owner may update / a non-owner
is `403` / unauthenticated is `401` / an admin may delete / a `ROLE_USER` is `403` /
the abort happens before any write / an unsecured type is ungated. See
[`docs/authorization.md`](../../docs/authorization.md) for the full surface.

## What's here

- **`src/Entity/`** — the Doctrine entities (the entity-backed types above). Their id
  mappings vary by strategy: store-provided types use a `#[ORM\GeneratedValue]`
  auto-increment integer PK; the app/client-keyed types (`playlists`, `genres`,
  `devices`) use a plain string PK with no DB generator.
- **`src/Resource/`** — the `AbstractResource` service classes (the
  field/relation/constraint DSL re-themed from core's example), with the multi-server
  attributes, the override attributes, and the per-type `Id` field strategy.
- **`src/Serializer/TrackSerializer.php`** and **`src/Hydrator/PlaylistHydrator.php`**
  — the override witnesses, each with a bound constructor argument (proving DI
  resolution).
- **`src/Provider/`** — the custom providers (`LibraryItemsProvider`, the
  priority-shadow `OverridingArtistProvider`, the `FavoriteProvider` polymorphic
  resolver, the `countries` reference-data provider, the standalone `charts`
  provider).
- **`src/EventListener/AuditLogSubscriber.php`** and **`src/Hook/`** — the
  lifecycle-hook witnesses: the cross-cutting audit/`serving`-gate subscriber, the
  `AuditLog` store it appends to, and the `HookAbortException` a before-hook throws
  to abort (the per-type resource-method hooks live on `PlaylistResource`).
- **`src/Security/PlaylistOwnerVoter.php`** — the ordinary Symfony Voter backing the
  `securityUpdate: "is_granted('EDIT', object)"` ownership gate on `PlaylistResource`
  (the declarative-authorization witness).
- **`config/`** — `bundles.php`, `services.yaml`,
  `packages/{framework,json_api,doctrine,security}.yaml`
  (the `admin` server lives under `json_api.servers`; `security.yaml` is the firewall
  behind the authorization witness), and `routes/json_api.yaml`
  (the default `.` import plus the per-server `admin` import under `/admin`).
- **`src/DataFixtures/Seed.php`** — plain deterministic fixtures (not Foundry) seeding
  a coherent object graph. The store-provided rows carry no hand-set ids — the
  database assigns them in persist order (`1, 2, 3, …`).
- **`tests/`** — the spec-grouped conformance suites, including
  **`tests/IdStrategyTest.php`** (the id-strategy matrix end to end).

## Running it

From the **bundle root** (`json-api-symfony/`):

```bash
composer test     # PHPUnit — includes the example suite
composer phpstan  # PHPStan level 9 over src + tests + examples
composer cs-check # PHP-CS-Fixer, PER-CS 2.0
```
