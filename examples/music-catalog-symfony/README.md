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
| `albums` | `Album` entity | multi-server (default + `admin`), directional `CompareField` (**merge-before-validate** witness on update), `Map releaseInfo` (JSON column), default-include `artist`, `WhereHas tracks`, **relation-scoped `filter`/`sort`** on `tracks` |
| `tracks` | `Track` entity | **serializer override** (`TrackSerializer`), `storedAs` rename, `ArrayList genres`, `like` filter, plain `belongsToMany playlists` (`cannotReplace`) |
| `playlists` | `Playlist` entity | **hydrator override** (`PlaylistHydrator`), **UUID id** (`uuid()->generated()`), derived `slug`, **lifecycle hooks** (`beforeCreate` stamp + `beforeDelete` 409 guard), **authorization** (`securityDelete` admin-only + `securityUpdate` owner Voter), **pivot** `belongsToMany orderedTracks` (`PlaylistEntry` association entity: required writable `position` + cross-pivot `weight >= position` + server-owned `addedAt` as `meta.pivot`, set/reordered via linkage `meta`, `?filter`/`?sort`, **merge-before-validate** per member) |
| `users` | `User` entity | **admin-server-only**, `UniqueEntity` on `email`, write-only `password`, validation-composition trio, `getAllowedIncludePaths` whitelist (include safeguard C) |
| `favorites` | `Favorite` entity | **polymorphic to-one** `MorphTo favoritable` (Track\|Album\|Artist) |
| `libraries` | `Library` entity | **polymorphic to-many** `MorphToMany items` (custom provider) |
| `genres` | `Genre` entity | **client-supplied natural-key id** (`requireClientId()->pattern(slug)`), **declarative HTTP cache headers** (`cacheHeaders` + `collection` override) |
| `devices` | `Device` entity | **app-generated ULID id** (`ulid()->generated()`), **self-link opt-out** (`emitsSelfLink()` false), **RFC 8594 deprecation** (`deprecation` + `sunset` + `sunsetLink`) |
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
**Bearer `access_token`** firewall (the most common API auth scenario, and the
scheme the shipped `JsonApiBrowser::actingAs()` uses; a tiny `AccessTokenHandler`
resolves the token to the seeded user, and a permissive `BearerTokenExtractor` lets
an email identifier through). The firewall is optional (no `access_control`), so an
unauthenticated request still reaches the controller and the security expressions are
what gate it. `tests/AuthorizationTest.php` proves the owner may update / a non-owner
is `403` / unauthenticated is `401` / an admin may delete / a `ROLE_USER` is `403` /
the abort happens before any write / an unsecured type is ungated — every request
authenticated with `actingAs($user)` over a Bearer token. See
[`docs/authorization.md`](../../docs/authorization.md) for the full surface.

## Self links by convention

The JSON:API spec **recommends** (SHOULD) two `self` links, and the bundle emits
both **by convention** — no hand-written `getLinks()` needed (core ADR 0054, bundle
ADR 0047). Both derive from ingredients the integration already has — the resource's
`baseUri`/`uriType`/`id` and the request URI — so they are **provider-agnostic**: the
Doctrine-backed `albums`/`tracks` and the in-memory `charts` render identical self
URLs. The example's `default` server base URI is `https://music.example`.

- **Resource-level** — every resource object carries
  `data.links.self = {baseUri}/{uriType}/{id}`, on the primary data, on every
  `?include`'d resource, and on a `201`-created resource:

  ```jsonc
  // GET /albums/1
  "data": { "type": "albums", "id": "1",
            "links": { "self": "https://music.example/albums/1" }, … }
  ```

  The path segment is the **`uriType`**, not the `type` — the standalone `charts`
  serializer implements `UriTypeAwareInterface`, so a `charts` object still links to
  `/charts/{id}` with no entity behind it.

- **Top-level document** — every data document carries `links.self` = the request
  URI (`{baseUri}{path}` plus the percent-encoded query when present), on single,
  collection, related and relationship documents (but **not** error documents). On a
  **paginated** collection the page's own per-page self wins, carrying the *resolved*
  `page[...]` params (brackets percent-encoded as `%5B`/`%5D`):

  ```
  GET /tracks                 → links.self https://music.example/tracks?page%5Bnumber%5D=1&page%5Bsize%5D=15
  GET /tracks?filter[title]=air → links.self …/tracks?filter%5Btitle%5D=air&page%5Bnumber%5D=1&page%5Bsize%5D=15
  GET /tracks/1/album         → links.self https://music.example/tracks/1/album
  ```

**Opting out.** A resource suppresses its *own* `data.links.self` by overriding
`emitsSelfLink()` to return `false`. `DeviceResource` is the witness:

```php
public function emitsSelfLink(): bool
{
    return false;
}
```

A `GET /devices/{id}` then carries **no** `data.links.self`, while the top-level
document `links.self` is **unaffected** — the opt-out is resource-scoped, not
document-scoped. (A hand-written `getLinks()` self still wins over the convention.)
`tests/SelfLinkTest.php` proves both links and the opt-out end to end.

## Cache and deprecation response headers

Two cross-cutting HTTP-response concerns are declared **purely as attribute
metadata** — no `getLinks()`, no after-hook. The bundle's route-scoped
`ResponseHeadersListener` reads the config off the matched type and writes the
headers (bundle ADR 0054).

**Declarative HTTP cache headers (`genres`).** A genre is reference/lookup data that
changes rarely, so its reads are long-cacheable. `GenreResource` declares
`cacheHeaders` — a one-hour client lifetime (`max_age`), a one-day CDN lifetime
(`s_maxage`), `public`, and `Vary: Accept` — plus a nested `operations.collection`
override that shortens just the **list**'s `max_age` (the collection churns more than
a single genre), layered over the resource-level directives:

```php
#[AsJsonApiResource(
    entity: Genre::class,
    cacheHeaders: [
        'max_age' => 3600, 's_maxage' => 86400, 'public' => true, 'vary' => ['Accept'],
        'operations' => ['collection' => ['max_age' => 300]],
    ],
)]
```

The directives compose onto `Cache-Control` (via `Response::setCache()`) and `Vary`,
and apply to a **successful `GET` only**:

```
GET /genres          → Cache-Control: max-age=300, public, s-maxage=86400   (collection override)
                       Vary: Accept
GET /genres/trip-hop → Cache-Control: max-age=3600, public, s-maxage=86400  (resource-level)
                       Vary: Accept
POST /genres         → no Cache-Control                                     (a write is never cached)
```

An undeclared type (`artists`) carries no `Cache-Control` at all — the layer is
opt-in per type, and a global `json_api.defaults.cache_headers` (unset in this
example) would supply a fleet-wide default a resource merges over.

**RFC 8594 deprecation + sunset (`devices`).** `devices` is a legacy endpoint slated
for removal, so `DeviceResource` declares a deprecation signal:

```php
#[AsJsonApiResource(
    entity: Device::class,
    deprecation: true,
    sunset: 'Sat, 31 Dec 2050 23:59:59 GMT',
    sunsetLink: 'https://music.example/deprecations/devices',
)]
```

Unlike caching, these ride **every** response for the type — a read *and* a write —
because a deprecated endpoint is deprecated regardless of method:

```
GET /devices/{id}  → Deprecation: true
                     Sunset: Sat, 31 Dec 2050 23:59:59 GMT
                     Link: <https://music.example/deprecations/devices>; rel="sunset"
POST /devices      → Deprecation + Sunset + Link  (a deprecated write is still deprecated)
                     …but still no Cache-Control
```

`tests/ResponseHeadersTest.php` witnesses both features end to end: the cache headers
on the genre collection vs a single genre, their absence on a write and on an
undeclared type, and the deprecation/sunset/link on a deprecated read and write.

## Relation-scoped filters and sorts

A relation may declare **its own** `filter`/`sort` keys that augment **only** its
related-collection endpoint `GET /{type}/{id}/{rel}` — never the primary
`/{relatedType}` collection. `AlbumResource`'s `tracks` relation is the witness:

```php
HasMany::make('tracks')->type('tracks')
    ->withFilters(Where::make('longerThan', 'length_seconds', '>'))
    ->withSorts(SortByField::make('duration', 'length_seconds'));
```

Both name the related `Track` entity's `length_seconds` column, which is **neither
a declared filter nor a declared sort** on the `tracks` resource — so the scoping is
observable end to end:

- on `GET /albums/1/tracks` both keys work, merged on top of the `tracks` resource's
  own vocabulary: `?filter[longerThan]=270` narrows the album's tracks, `?sort=duration`
  orders them, and they compose with a `tracks` key like `?filter[title]=air` (both
  AND together) while the related type's `explicit` default filter still hides the
  explicit track;
- on the primary `/tracks` collection both keys are **absent** — `GET
  /tracks?filter[longerThan]=270` or `?sort=duration` is a `400`, because only the
  `tracks` resource's own `like`/`explicit`/`genres` filters and `title`/`trackNumber`
  sorts apply there.

That is the point of declaring on the relation rather than the related resource: a
contextual filter/sort stays scoped to the one endpoint where it is meaningful.

A relation-scoped filter/sort operates on the **related entity** (the common case,
as `length_seconds` above) and works out of the box through the existing provider
handlers. To filter/sort by a **pivot column** — a value carried on the join itself —
see the next section: a plain join table cannot hold one, so the pivot path models
the join as an association entity. `tests/RelationScopedParamsTest.php` proves the
witness above; see [`docs/relationships.md`](../../docs/relationships.md) for the
full surface.

## Counting a to-many relation

A to-many relation marked **`countable()`** opts into exposing its cardinality as
`meta.total` — the count is **pushed down** (never materialising the collection) and,
across a page of parents, **batched** into one grouped query per relation (no N+1).
Counting is **off by default**; this example marks `albums.tracks`,
`playlists.tracks` and `tracks.playlists` countable and deliberately leaves
`artists.albums` non-countable to witness both paths.

```php
// AlbumResource
HasMany::make('tracks')->type('tracks')->countable();
```

A countable relation surfaces a total two ways, both keyed `total`
(`tests/RelationCountTest.php`):

- **`?withCount=rel1,rel2`** — a flat primary-request parameter naming relationships
  (like `?include`) — activates `meta.total` on the **relationship object** when the
  parent is rendered:

  ```
  GET /albums/1?withCount=tracks   → data.relationships.tracks.meta.total = 3
  GET /albums?withCount=tracks     → each album's own tracks.meta.total, BATCHED
                                     (album 1 has 3 tracks, album 2 has 1) — one
                                     grouped count across the page, never per-parent
  ```

  The relationship-object total is gated by `countable()` **and** being named in
  `?withCount` (a countable relation **not** named carries no total). Naming a
  relation that is not `countable()`, a to-one, or an unknown name is a clean
  **`400`** (`source.parameter: withCount`) — `?withCount=orderedTracks` (a
  non-countable to-many), `?withCount=artist` (a to-one) and `?withCount=nope` all
  reject up front.

- the **related-collection endpoint** of a countable relation pays for the count:
  `GET /albums/1/tracks` emits `meta.page.total` and a `last` pagination link (gated
  by `countable()` **alone**, independent of `?withCount` — it is a pagination
  feature). A **non-countable** relation's endpoint paginates **count-free**: `GET
  /artists/1/albums` runs **no** count query, so there is **no** `meta.page.total`
  and **no** `last` link — only `self`/`first` (and a `next` when a further page
  exists). That count-free related page is the partial-pagination mechanism for
  relationships.

A cursor-paginated relation marked countable still honours the total where asked —
the author opted into the cost by declaring `countable()`. See
[`docs/relationships.md`](../../docs/relationships.md) (bundle ADR 0052, core ADR
0057) for the full surface.

## Filtering and sorting a relationship from the primary request

The relation-scoped `filter`/`sort` vocabulary above is normally reachable only on
the related-collection endpoint (`GET /albums/1/tracks`). The **Relationship
Queries** profile lets a client reach it from the **primary** request — ordering and
narrowing a relationship's linkage as it is rendered (included, or links-only), with
no second round-trip. It is **opt-in**: the bundle registers and advertises the
profile, but the `relatedQuery` / `rQ` family is parsed only when the client
**negotiates** the profile URI in the `Accept` header's `profile` media-type
parameter — otherwise it is ignored entirely (today's behaviour) and the profile is
not advertised.

```
Accept: application/vnd.api+json;profile="https://haddowg.dev/profiles/relationship-queries"
```

With the profile negotiated, the client addresses a relationship **by its include
path** and supplies a per-relationship `sort` / `filter`. Both the canonical
`relatedQuery` family and the `rQ` shorthand are spec-compliant custom query families
(each base carries an uppercase letter). A dotted path (`relatedQuery[albums.tracks]`)
is legal family **grammar** — it parses without error — but the bundle windows only the
**top-level** relations of the request's primary resource, so a dotted path addressing
a relation of an *included* resource is rejected as an unknown path (`400`), not silently
ignored; address such a relation at its own endpoint instead. `page` is **out** of the
profile — an addressed relationship always renders **page 1** (the relation's default
size), navigated via its own relationship-object pagination links. This example
witnesses it over the album's top-level `tracks` relation
(`tests/RelationshipQueriesProfileTest.php`):

```
# order the included tracks by -duration; the linkage data AND the included
# resources reflect that page-1 order (Airbag 284s, Exit Music 264s — Paranoid
# Android is explicit and hidden by the related tracks default filter)
GET /albums/1?include=tracks&relatedQuery[tracks][sort]=-duration
  → data.relationships.tracks.data = [tracks/1, tracks/3]

# narrow the linkage by the relation's own scoped filter
GET /albums/1?include=tracks&relatedQuery[tracks][filter][longerThan]=280
  → [tracks/1]

# a collection include windows EACH parent's relationship to its own page 1
GET /albums?include=tracks&relatedQuery[tracks][sort]=-duration
  → album 1 → [tracks/1, tracks/3];  album 2 → [tracks/4]

# the rQ shorthand is identical; on a [path][op] conflict the canonical wins
GET /albums/1?include=tracks&rQ[tracks][sort]=-duration
```

- The vocabulary is exactly the related-collection **endpoint's**: the related
  `tracks` resource's own keys (`?...[sort]=title`, `?...[filter][explicit]=true`)
  **merged** with the relation's scoped `duration`/`longerThan` (bundle ADR 0044). An
  **unknown** key is the same `400` as the endpoint, with the **plain-form** key in
  `source.parameter` (`filter[nope]`, not the `relatedQuery[…]` form).
- A rendered to-many under the profile carries its pagination links — `first`/`prev`/
  `next` (+`last` **only when the relation is `countable()`**, preserving the Slice 1
  count-free vs countable distinction) — in its relationship object's `links`, in the
  spec's **plain form** against the relationship-linkage endpoint
  (`/albums/1/relationships/tracks?sort=-duration&page[number]=2`), **never** the
  `relatedQuery[…]` form. `albums.tracks` (countable) emits a `last`; `users.playlists`
  (non-countable) paginates count-free with no `last`.

The bundle's `docs/relationships.md` (bundle ADR 0053, core ADR 0058) documents the
full surface. On a **collection** include each parent's relationship is windowed to its
own page 1 independently by a **portable per-parent query** on every provider — the
Doctrine provider reuses its per-parent scoped (or many-to-many `IN`-subquery) query,
the in-memory provider applies the criteria in PHP. A single windowed native batch
(`ROW_NUMBER() OVER (PARTITION BY …)` where the platform supports window functions) is
a **deferred optimization, not a correctness requirement** (bundle ADR 0053). A relation
whose linkage is *derived* rather than a plain Doctrine association (the pivot
`playlists.orderedTracks` here) is addressed at its own endpoint, not from a parent
request.

## Strict query parameters

An **unrecognized** top-level query-parameter family is **rejected with a `400`**
rather than silently dropped — so a client typo (`?pag[number]=2` for `page[number]`,
a misspelled custom param) surfaces as an error instead of a wrong-but-`200` result.
This is **on by default** (`json_api.strict_query_parameters`, bundle ADR 0055, core
ADR 0059); the shipped app's `config/packages/json_api.yaml` omits the key, so it is
already strict here (`tests/StrictQueryParametersTest.php`):

```
GET /albums?bogus=1          → 400  QUERY_PARAM_UNRECOGNIZED  source.parameter: bogus
GET /albums?pag[number]=2    → 400  QUERY_PARAM_UNRECOGNIZED  source.parameter: pag
GET /albums?myParam=1        → 400  QUERY_PARAM_UNRECOGNIZED  source.parameter: myParam
```

A family is **recognized** (no `400`) when its base name is a reserved JSON:API family
used well-formed (`include`/`fields`/`filter`/`sort`/`page`), a key the primary resource
**declares**, or a **known custom param** — the always-on `?withCount`, and the
Relationship Queries profile's `relatedQuery`/`rQ` family **when the profile is
negotiated**:

```
GET /albums?include=tracks&fields[albums]=title&filter[tracks]=1&sort=title&page[number]=1
                             → 200  (every reserved family, used well-formed)
GET /albums/1?withCount=tracks
                             → 200  (the always-on withCount)
GET /albums/1?include=tracks&relatedQuery[tracks][sort]=-duration
  Accept: …;profile="…/relationship-queries"
                             → 200  (relatedQuery, profile negotiated)
GET /albums/1?include=tracks&relatedQuery[tracks][sort]=-duration
                             → 400  (relatedQuery, profile NOT negotiated)
```

An undeclared key *inside* a recognized family is unaffected by this check; it still
flows to the family's own key validation (`filter[nope]` → `400 FILTERING_UNRECOGNIZED`,
the "Counting"/profile sections above).

Two layers reject an unrecognized family, both before dispatch: core's **always-on**
spec baseline (the spec mandates a `400` for an all-`a-z` custom param) owns `bogus` and
`pag`, while the **strict** superset adds a *well-named* unsupported param (one carrying
a non-`a-z` char, such as `myParam`). Setting `strict_query_parameters: false` stands
down **only the superset**, so `?myParam=1` (and an un-negotiated `?relatedQuery[…]`)
flips back to a silent ignore — but the misspelled-family `?bogus=1`/`?pag[number]=2`
stay a `400`, since the baseline never relaxes. The relaxed path is exercised by
`tests/RelaxedQueryParametersTest.php` against a kernel variant with the toggle off
(`tests/RelaxedQueryParametersKernel.php`). See
[`docs/configuration.md`](../../docs/configuration.md#strict_query_parameters) and
[`docs/errors.md`](../../docs/errors.md).

## Validating filter values

A value-carrying filter can **declare value constraints**, validated by the bundle
**before** the filter reaches the provider — so a mistyped value is a clean `400`
instead of the provider's unhelpful default. Before this, a filter's value was
metadata-only: `filter[longerThan]=banana` on the integer `length_seconds` column
flowed straight to Doctrine, where a strict driver such as Postgres surfaces a
**`500`** (a PDO type-mismatch error) while a loosely-typed database (the sqlite
this example runs on) and the in-memory provider just silently match nothing.

Constraints are declared with the same fluent shortcuts the `Id` field already uses,
reusing core's constraint vocabulary — `->integer()`, `->numeric()`,
`->uuid(?int $version)`, `->boolean()`, `->pattern(string $regex)`, or `->constrain(…)`
for any constraint VO. Two witnesses in this example, **both over the reference
Doctrine provider**:

```php
// primary collection — TrackResource (a boolean filter)
Where::make('explicit')->asBoolean()->default(false)->boolean(),

// related collection — AlbumResource's relation-scoped tracks filter (an int column)
HasMany::make('tracks')->type('tracks')
    ->withFilters(Where::make('longerThan', 'length_seconds', '>')->integer()),
```

So (`tests/FilterValueConstraintTest.php`):

- `GET /tracks?filter[explicit]=banana` is a clean **`400`**, not a silent coercion
  to `false`; `GET /albums/1/tracks?filter[longerThan]=banana` is a clean **`400`**
  too — the bad value never reaches the query, so on a strict driver (Postgres)
  there is no `500` (the sqlite kernel here would otherwise silently non-match). The
  error document is `status "400"`, `code "FILTER_VALUE_INVALID"`,
  `title "Filter value is invalid"`, `detail` = the violation message,
  `source: { "parameter": "filter[<key>]" }` — one error per violation. It is a
  **`400`** (a bad query *parameter*), deliberately not a `422` (a document semantic
  error);
- a **valid** value still filters exactly as before — `filter[explicit]=true` surfaces
  the explicit track, `filter[longerThan]=270` narrows the related collection;
- a filter with **no declared constraints** is unaffected (`filter[title]=air` takes
  any value), and an **author-set `default()`** is trusted — only client-supplied
  values are validated, never the default (`explicit` defaults to `false` against its
  own `->boolean()` and the bare collection is a `200`).

The validation reuses the [validator bridge](../../docs/validation.md)
(`symfony/validator`), so a constrained filter with no validator installed is inert —
the same optionality the attribute-constraint bridge has. No new config keys. See
[`docs/data-layer.md`](../../docs/data-layer.md) (bundle ADR 0048, core ADR 0055).

## Validating the merged resource on update

A `PATCH` carries a **partial** document — only the fields the client means to
change. A cross-field or conditional rule that depends on a sibling the client did
**not** re-send must still see that sibling, so on an update the bundle validates the
**merged** resource: the stored attribute values overlaid by the incoming partial
(an incoming key overrides; an absent key keeps its stored value). The existing
record is already loaded in the handler, so no extra read is needed.

`AlbumResource` declares the directional `availableUntil > availableFrom`
`CompareField`. Album `1` is stored with `availableFrom` `1997-05-21`, so
(`tests/ValidationTest.php`):

```jsonc
// the body carries ONLY availableUntil — availableFrom is never re-sent
PATCH /albums/1
{ "data": { "type": "albums", "id": "1",
            "attributes": { "availableUntil": "2040-01-01" } } }   // 200 — 2040 > stored 1997

PATCH /albums/1
{ "data": { "type": "albums", "id": "1",
            "attributes": { "availableUntil": "1990-01-01" } } }   // 422 — 1990 < stored 1997
```

The comparison runs against the **merged** state — the stored `availableFrom` is
folded under the body — so the rule holds (or fails) correctly even though the
sibling is absent from the wire. A required attribute already valid in stored state
likewise need not be re-sent: the merge folds the stored value in, so a partial
`PATCH` that omits it stays a `200` rather than a false `422`. The same merge
semantics apply **per relationship member** to pivot meta — see the next section. See
[`docs/validation.md`](../../docs/validation.md) (bundle ADRs 0049/0050).

## Write-only attributes (a credential a client sets but never reads back)

`readOnly()` renders a field but never hydrates it. `writeOnly()` is the **exact
inverse**: a field **accepted on write** (create *and* update, still validated) but
**never rendered** — exactly what a write-once secret needs. `UserResource` declares

```php
Str::make('password')->writeOnly()->minLength(8)->requiredOnCreate()
```

so a client may set `password` on a `POST`/`PATCH` (where it is validated like any
other attribute), but it appears on **no** read (`tests/WriteOnlyAttributeTest.php`):

```jsonc
POST /admin/users                                    // 201 — password accepted + stored
{ "data": { "type": "users", "attributes": {
    "email": "grace@example.com", "displayName": "Grace",
    "preferences": {}, "password": "longpassword1" } } }
// → the 201 body carries email/displayName/preferences — but NO password

POST /admin/users  { …no password… }                 // 422 source.pointer /data/attributes/password
POST /admin/users  { …"password": "tiny"… }          // 422 (minLength 8)

GET  /admin/users/1                                   // single read — no password
GET  /admin/users                                     // collection — no password on any row
GET  /admin/users/1?include=playlists                // compound doc — no password anywhere
GET  /admin/users/1?fields[users]=password           // sparse fieldset naming it → empty attributes
```

The skip lives in the serializer's attribute render — the one path that produces
**every** resource object, primary or `included` — and runs **before** sparse-fieldset
filtering, so a `fields[users]=password` cannot resurrect it. The value is genuinely
stored (the test asserts it against the persisted `User` entity, not just the
response). `passwordConfirm` is `computed()` **and** `writeOnly()` — validated against
`password` but never persisted and never echoed. Declaring a field both `writeOnly()`
and `readOnly()` is a `\LogicException` at declaration (it could be neither read nor
written). Core ADR 0060.

## Pivot data on an association entity

A `belongsToMany` may carry **pivot data** — values that live on the *join* between
the two resources, not on either resource itself (a track's `position` in a
playlist, the `addedAt` timestamp). The catch is a hard Doctrine fact: a plain
`#[ORM\ManyToMany]` join table holds **only the two foreign keys**, so Doctrine
cannot map a `position`/`addedAt` column on it. To *have* pivot columns the join
must be modelled as an **association entity**:

```
Playlist ──OneToMany──▶ PlaylistEntry ──ManyToOne──▶ Track
                       { int position;
                         DateTimeImmutable addedAt;
                         ManyToOne playlist;
                         ManyToOne track }
```

`PlaylistResource` is the witness. Alongside the plain `belongsToMany` `tracks` (a
bare `playlist_track` join table, **no** pivot columns), it declares a second
`belongsToMany` `orderedTracks` to the same `tracks` type, backed by the
[`PlaylistEntry`](src/Entity/PlaylistEntry.php) association entity:

```php
BelongsToMany::make('orderedTracks')->type('tracks')
    ->fields(
        Integer::make('position')->required()->min(1),               // writable, required-on-create
        Integer::make('weight')->compareWith('position', Comparison::GreaterThanOrEqual), // cross-pivot rule
        DateTime::make('addedAt')->readOnly(),                        // server-owned
    )
    ->extractUsing(/* map the parent's entries to their far tracks */)
    ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
```

Declaring `fields()` is what turns the relation pivot-backed. Those fields are the
**same field DSL** the resource uses for attributes (`Integer`/`DateTime`/`Str`/…),
with their constraints, defaults and `->readOnly()` — so `position` is **writable**
(no `->readOnly()`, set from the linkage `meta` on a write) and `addedAt` is
server-owned. The Doctrine adapter **auto-detects** the association entity —
`PlaylistEntry` is the only to-many on `Playlist` whose target also has a
`ManyToOne` to `Track`, so no `->through()` override is needed (add
`->through(PlaylistEntry::class)` only when detection is ambiguous or finds none,
else it throws a clear `LogicException`). It then runs **one** DQL statement over
that entity to fetch the page, render the pivot values, filter/sort and paginate —
no second query, correct pagination.

**How it renders / filters / sorts** (`tests/PivotTest.php`):

- pivot values render as `meta.pivot` on **each member** of both
  `GET /playlists/{id}/orderedTracks` (full resources) and
  `GET /playlists/{id}/relationships/orderedTracks` (linkage identifiers), typed per
  the declared fields;
- the pivot field names become recognised `?filter`/`?sort` keys **on that related
  endpoint only**: `?sort=position` / `?sort=-position` orders (and flips),
  `?filter[position]=2` narrows, and they **compose** with the related `tracks`
  resource's own `?filter[title]` in one correctly-paginated query (no short page).

**Writing / reordering pivot data** (`tests/PivotTest.php`): a writable pivot field
is set through the linkage member's **resource-identifier `meta`** (JSON:API permits
meta on a resource identifier) — on the relationship endpoints AND inline in a
whole-resource write. The Doctrine persister diffs the association entity: it UPSERTS
each incoming member (updating an existing row's writable fields **in place** —
that's the reorder — or creating one), and on a full `PATCH` replacement DROPS the
rows whose member is not in the incoming set.

```jsonc
// add Mysterons at position 4 (creates the PlaylistEntry row)
POST  /playlists/{id}/relationships/orderedTracks
{ "data": [ { "type": "tracks", "id": "4", "meta": { "position": 4 } } ] }

// full replace = reorder the existing rows IN PLACE + drop dropped members
PATCH /playlists/{id}/relationships/orderedTracks
{ "data": [ { "type": "tracks", "id": "1", "meta": { "position": 1 } },
            { "type": "tracks", "id": "3", "meta": { "position": 2 } } ] }

// the SAME meta inline in a whole-resource write
PATCH /playlists/{id}
{ "data": { "type": "playlists", "id": "{id}", "relationships": {
    "orderedTracks": { "data": [ { "type": "tracks", "id": "1", "meta": { "position": 1 } } ] } } } }
```

- a **reorder updates the existing row in place** — the server-owned `addedAt`
  survives (it is stamped by `PlaylistEntry`'s `#[ORM\PrePersist]` only on a
  *freshly-created* row);
- a pivot value violating a field constraint (`position` `0` vs `min(1)`) is a
  **`422`** pointed at `…/meta/position` (the relationship endpoint) or
  `/data/relationships/orderedTracks/data/{n}/meta/position` (a whole-resource
  write), with **no write** — the store is unchanged;
- **merge-before-validate, per member** (bundle ADR 0050): an update validates each
  member against its **merged** pivot row (the stored values overlaid by the incoming
  `meta`). So a *genuinely-new* member missing the required `position` is a `422`
  (the new-row context — never a DB NOT-NULL `500`), but re-asserting an *existing*
  member with no `position` is a **`200`**: the required field is taken from the
  merged stored row and **preserved** (it need not be re-sent). The cross-pivot
  `weight >= position` rule likewise compares an incoming `weight` against the merged
  stored `position` — set `weight` alone and the omitted `position` is still its
  operand. (See the merge-before-validate witnesses in `tests/PivotTest.php`.)
- a **`readOnly` field supplied in `meta` is ignored** — `addedAt` is server-owned,
  so a supplied value is never written (the row keeps its server default);
- `DELETE` carries no pivot — it removes the incoming members' rows;
- mutating the playlist's relationship goes through the same `securityUpdate` owner
  gate as any other update, so the writes authenticate as the playlist's owner.

**Boundaries** (both tested):

- **Doctrine-only** — the in-memory provider has no association entity to query, so
  a pivot key 400s there, no `meta.pivot` renders, and a pivot-`meta` write is
  silently ignored.
- **Scoped** — a pivot key is unrecognised (`400`) on the primary `/tracks`
  collection and on the plain `tracks` relation; it is meaningful only on
  `orderedTracks`.
- **One pivot row per member** — `meta.pivot` is a single value set per member. If a
  track were added to the playlist twice (duplicate membership), the collection
  returns one row per distinct member: the total counts distinct members, no member
  splits across pages, and the rendered values are a representative membership row.

See [`docs/relationships.md`](../../docs/relationships.md) and
[`docs/doctrine.md`](../../docs/doctrine.md) for the full surface (bundle ADR 0045).

## What's here

- **`src/Entity/`** — the Doctrine entities (the entity-backed types above), plus the
  `PlaylistEntry` **association entity** backing the `orderedTracks` pivot relation
  (a non-resource entity — it carries the `position`/`weight`/`addedAt` join columns). Their id
  mappings vary by strategy: store-provided types use a `#[ORM\GeneratedValue]`
  auto-increment integer PK; the app/client-keyed types (`playlists`, `genres`,
  `devices`) use a plain string PK with no DB generator.
- **`src/Resource/`** — the `AbstractResource` service classes (the
  field/relation/constraint DSL re-themed from core's example), with the multi-server
  attributes, the override attributes, and the per-type `Id` field strategy.
- **`src/Serializer/TrackSerializer.php`** and **`src/Hydrator/PlaylistHydrator.php`**
  — the override witnesses, each with a bound constructor argument (proving DI
  resolution).
- **`src/Provider/`** — the custom providers (`LibraryItemsProvider` — the
  polymorphic `libraries.items` resolver, which also delegates the pivot seam to the
  Doctrine provider since it shadows `tracks`; the priority-shadow
  `OverridingArtistProvider`; the `FavoriteProvider` polymorphic resolver; the
  `countries` reference-data provider; the standalone `charts` provider).
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
  **`tests/IdStrategyTest.php`** (the id-strategy matrix end to end),
  **`tests/SelfLinkTest.php`** (the convention resource + top-level `self` links and
  the `devices` opt-out), **`tests/ResponseHeadersTest.php`** (the declarative
  `genres` cache headers + the `devices` RFC 8594 deprecation/sunset signal),
  **`tests/RelationCountTest.php`** (countable relations:
  `?withCount` relationship-object totals, batched across a collection, and the
  count-free related endpoint), and **`tests/RelationshipQueriesProfileTest.php`**
  (the Relationship Queries profile: per-relationship `sort`/`filter` from the primary
  request, per-parent windowing on a collection include, the `rQ` shorthand, an
  unknown-key `400`, and the count-free vs countable relationship-object pagination
  links — all gated on negotiating the profile), and
  **`tests/StrictQueryParametersTest.php`** + **`tests/RelaxedQueryParametersTest.php`**
  (strict query-parameter validation: an unrecognized family is a `400` by default, the
  recognized set stays `200`, and the relax toggle flips a well-named param back to a
  silent ignore over `tests/RelaxedQueryParametersKernel.php`), and
  **`tests/WriteOnlyAttributeTest.php`** (the write-only `users.password`: accepted +
  stored on create/update, still validated, and absent from every read — single,
  collection, compound `?include`, and a sparse fieldset naming it).

## Running it

From the **bundle root** (`json-api-symfony/`):

```bash
composer test     # PHPUnit — includes the example suite
composer phpstan  # PHPStan level 9 over src + tests + examples
composer cs-check # PHP-CS-Fixer, PER-CS 2.0
```
