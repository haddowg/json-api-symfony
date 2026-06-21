# Configuration reference (`json_api:`) and optional dependencies

The bundle's configuration tree is intentionally tiny. Most of what makes a
JSON:API type work — discovery, routing, the data layer, validation — is wired by
**service tags and autoconfiguration**, not configuration keys (see
[capability composition](capability-composition.md) and [the data layer](data-layer.md)).
What `json_api:` configures is the small set of values that can't fall out of
discovery: the API's `base_uri` and `version`, the server default paginator's
page-size cap, the `?include` nesting-depth cap, an optional structural linter toggle,
and — for the multi-API case — additional named servers.

There is **no `Configuration.php` and no `Extension` class**. The tree is declared
inline in the bundle's `configure()` (an `AbstractBundle`), and the extension alias
`json_api` is auto-derived from the bundle name. The whole tree lives in
[`JsonApiBundle::configure()`](../src/JsonApiBundle.php).

## The config tree

Ten top-level keys, all optional:

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'
    version: '1.1'
    max_include_depth: 3
    strict_query_parameters: true
    pagination:
        max_per_page: 50
    doctrine:
        window_functions: true
    atomic_operations:
        enabled: false
    defaults:
        cache_headers:
            max_age: 60
            public: true
    servers:
        admin:
            base_uri: 'https://admin.music.example'
```

— [`config/packages/json_api.yaml`](../examples/music-catalog-symfony/config/packages/json_api.yaml)
(the example app's real config; the `admin` server is the [multi-server](multi-server-and-testing.md)
witness).

| Key | Type | Default | What it does |
| --- | --- | --- | --- |
| `base_uri` | scalar | `''` | The base prepended to every generated link. **Empty (the default): links are absolute, built from the request's scheme + host** — each tenant/host gets correct self-links with no hardcoded host. Set a value to pin a fixed canonical base regardless of the request. Trailing slashes are tolerated. |
| `version` | scalar | `'1.1'` | The JSON:API version the implicit `default` server advertises. |
| `max_include_depth` | int | `3` | The cap on `?include` nesting depth (relationship hops from the primary resource). `0` is unlimited. A resource's own `maxIncludeDepth()` overrides it. |
| `strict_query_parameters` | bool | `true` | Reject an unrecognized top-level query-parameter family — and an unknown [`fields[type]` sparse-fieldset member](#unknown-sparse-fieldset-members) — with a `400` (ADR 0055). `false` restores the old silent-ignore behaviour. |
| `pagination.max_per_page` | int | `100` | The page-size cap the built-in server default paginator clamps `page[size]`/`page[limit]` to. `0` installs no built-in default (those collections render unpaginated). |
| `doctrine.window_functions` | bool | `true` | Use SQL window functions (`ROW_NUMBER`/`COUNT OVER`) for the bounded windowed-include batch (ADR 0065). Requires MySQL ≥ 8, MariaDB ≥ 10.2, SQLite ≥ 3.25, or any PostgreSQL. On an older engine the default `true` throws a `500` at the first windowed include — set `false` for the per-parent bounded fallback. |
| `atomic_operations.enabled` | bool | `false` | Emit the [Atomic Operations](atomic-operations.md) endpoint (`POST {path}`) per server (ADR 0087/0088). |
| `atomic_operations.path` | scalar | `/operations` | The path the Atomic Operations endpoint is served at. Must not equal a resource's collection path (the loader fails fast if it does). |
| `schema_validation` | bool | `false` | Registers the optional opis structural linter. Enabling it without `opis/json-schema` **fails the build**. |
| `defaults.cache_headers` | map | `{}` | Fleet-wide default HTTP cache directives for `GET` reads (ADR 0054). A resource's own `cacheHeaders` overrides these. |
| `defaults.deprecation` / `sunset` / `sunset_link` | scalar | `null` | Fleet-wide default deprecation/sunset headers (ADR 0054). A resource's own `deprecation`/`sunset` overrides these. |
| `servers` | map | `[]` | Additional **named** servers, keyed by name (ADR 0034). |

`base_uri` and `version` configure the implicit `default` server — link core
[server.md](https://github.com/haddowg/json-api/blob/main/docs/server.md) for what
a core `Server` does with them. A single-API app sets just `base_uri` (and usually
nothing else) and never touches `servers:`.

> **Empty `base_uri` (the default) emits request-host-absolute links** — a request to
> `https://albums.example/albums/1` renders `"self": "https://albums.example/albums/1"`,
> scheme and host taken from the incoming request's origin. That is the **multi-tenant /
> multi-host recipe**: one deployment served under several hostnames emits correct
> absolute self-links per host with no per-request configuration. Behind a TLS-terminating
> proxy this works as long as the request is proxy-aware — configure Symfony's
> [trusted proxies](https://symfony.com/doc/current/deployment/proxies.html) so the public
> scheme/host (`X-Forwarded-Proto`/`X-Forwarded-Host`) flow into the request the bundle
> reads. Set a non-empty `base_uri` only when every link must carry a single fixed
> canonical host regardless of the request; it is a compile-time constant applied to every
> request and is **not** request-host aware. Trailing slashes on a configured base are
> tolerated (`https://api.example/` and `https://api.example` are equivalent).

### Container parameters

Two of the keys surface as container parameters you can read elsewhere:

| Parameter | Source | Value |
| --- | --- | --- |
| `haddowg_json_api.base_uri` | `base_uri` | the configured (or empty) base URI |
| `haddowg_json_api.version` | `version` | the configured (or `'1.1'`) version |
| `haddowg_json_api.max_include_depth` | `max_include_depth` | the resolved include-depth cap (default `3`, `0` = unlimited) |
| `haddowg_json_api.pagination.max_per_page` | `pagination.max_per_page` | the resolved page-size cap (default `100`, `0` = no built-in default) |
| `haddowg_json_api.doctrine.window_functions` | `doctrine.window_functions` | whether the Doctrine provider runs the native windowed-include batch (default `true`) or the per-parent bounded fallback |
| `haddowg_json_api.servers` | derived | the list of all server names, e.g. `['default', 'admin']` |

`haddowg_json_api.servers` is the resolved name list — always including the implicit
`default` — that the compiler pass reads to validate resource-to-server assignments
and bucket each type onto the right server.

### `pagination.max_per_page`

The bundle gives every server a **default paginator** — by default a
page-number/page-size strategy
([core `PagePaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies)) —
and `pagination.max_per_page` is the **cap** it clamps a client's `page[size]` to.
A client controls the page size, so without a ceiling `?page[size]=1000000` would
force your store to fetch a million rows — a page-size denial-of-service vector.
The cap closes it by clamping the resolved size down to the maximum (the same
clamp-don't-`400` stance core takes for every garbage `page[…]` value), so an
over-large request returns the capped number of items with a `200` and
`meta.page.perPage` reports the cap.

```yaml
json_api:
    pagination:
        max_per_page: 50   # ?page[size]=1000 → 50 items; default 100
```

The cap is **on by default at `100`** — every collection resolving to the server
default is protected without any configuration. Set `0` to install **no** built-in
default paginator: collections that resolve to the server default then render
unpaginated (the whole list, no `page` links). A resource (or relation) that
declares its own
[`pagination()`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#declaring-a-paginator)
paginator overrides the server default entirely and sets its own cap with
[`withMaxPerPage()`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#capping-the-page-size).
The cap concept, the wither and the disable-with-`0` semantics are all owned by
**core** — see core
[pagination.md → Capping the page size](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#capping-the-page-size).

### `max_include_depth`

A compound document can grow without bound in two ways: a deeply nested
`?include=a.b.c.d.e` walks the relationship graph as far as the client asks, and a
**default-included** relation pointing back at its own type (or a mutual pair — `A`
default-includes `B`, `B` default-includes `A`) recurses the renderer forever. Both are
denial-of-service vectors, and the second is a latent infinite loop. `max_include_depth`
closes both: it caps the **nesting depth** of an `?include`, where depth is the number
of relationship hops from the primary resource — `?include=a` is depth 1,
`?include=a.b.c` is depth 3.

```yaml
json_api:
    max_include_depth: 3   # a.b.c ok; a.b.c.d → 400
```

The cap is **on by default at `3`** — a request for a path deeper than the cap is a
`400` (`INCLUSION_DEPTH_EXCEEDED`), and a default-include cascade silently stops at the
cap (so a mutual default-include cycle terminates rather than looping). Set `0` for
**unlimited** (core's unopinionated default). A resource overrides the server default
for requests where it is the primary/root type by implementing core's
`IncludeControlsInterface::maxIncludeDepth()`:

```php
final class CommentResource extends AbstractResource
{
    public function maxIncludeDepth(): ?int
    {
        return 1; // this type only ever compounds one hop deep
    }
}
```

The depth cap is one of three composing **include safeguards** (bundle ADR 0037). The
other two are author-declared, not config: a per-relation `cannotBeIncluded()` opt-out
and a root-scoped allowed-include-paths whitelist
(`IncludeControlsInterface::getAllowedIncludePaths()`). See
[relationships → controlling what can be included](relationships.md#controlling-what-can-be-included)
for all three. The depth-cap concept and the `IncludeControlsInterface` seam are owned by
**core**; the bundle supplies only the opinionated default of `3` and threads it to each
server.

### Customising the server default paginator

The built-in default is `PagePaginator`. To make a server default to a different
strategy — a [`CursorPaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies),
an `OffsetPaginator`, or your own — register a `PaginatorInterface` service under a
**conventional id**. The `ServerFactory` reads it via `nullOnInvalid()`, so
registering the service is the entire wiring (no tag, no compiler pass). Two ids,
resolved **by-server-first then generic**:

| Service id | Applies to |
| --- | --- |
| `haddowg.json_api.default_paginator` | every server (the generic default) |
| `haddowg.json_api.default_paginator.<name>` | one server only (overrides the generic for that server) |

```yaml
# config/services.yaml
services:
    # Cursor pagination for every server's default…
    haddowg.json_api.default_paginator:
        class: haddowg\JsonApi\Pagination\CursorPaginator

    # …but keep the page-number default on the `admin` server only.
    haddowg.json_api.default_paginator.admin:
        class: haddowg\JsonApi\Pagination\PagePaginator
        factory: [haddowg\JsonApi\Pagination\PagePaginator, make]
```

A custom paginator owns its own page-size ceiling, so `pagination.max_per_page`
applies only to the built-in `PagePaginator` fallback — set a cap on your own
paginator with its own `withMaxPerPage()` where the strategy supports one. The full
resolution order a server walks is: this-server service → generic service →
built-in capped `PagePaginator` (when `max_per_page > 0`) → none (`max_per_page: 0`).
As always, a resource/relation `pagination()` still wins over whatever the server
resolves.

### `schema_validation`

`schema_validation` is an optional dev/CI **structural** linter, distinct from the
always-relevant semantic validation. When `true`, the bundle wires core's
`DocumentValidator` + `VendoredSchemaProvider` so write bodies are checked against
the JSON:API JSON Schema before they reach the handler (a `400` on a malformed
document) — link core
[schema-validation.md](https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md)
for what the linter checks. It is off by default and is **not** a substitute for the
[Symfony Validator bridge](validation.md), which validates *values* against your
declared constraints (a `422`).

`opis/json-schema` is a `suggest` dependency, so enabling `schema_validation`
without it is a wiring mistake the bundle catches at container-build time:

```
json_api.schema_validation is enabled but opis/json-schema is not installed;
require opis/json-schema (dev/CI) to use the structural document linter.
```

You can toggle it per-environment by layering a partial override — the example app's
[`SchemaValidationKernel`](../examples/music-catalog-symfony/tests/SchemaValidationKernel.php)
does exactly this, merging `['schema_validation' => true]` over the shipped config so
`base_uri`/`servers` stay unchanged.

### `strict_query_parameters`

By default the bundle **rejects an unrecognized top-level query-parameter family
with a `400`** (ADR 0055, core ADR 0059). Before this, a misspelled or unsupported
family was silently dropped — so `?filtr[title]=x` (a typo for `filter`) returned a
wrong-but-`200` collection instead of an error, and a client could not tell its
query was ignored. Strict mode turns that silent failure into a loud one.

A family is **recognized** when its base name is:

- a reserved JSON:API family — `include`, `fields`, `filter`, `sort`, `page` (their
  *internal* key validation is unchanged: an unknown `filter[…]`/`sort` key or a bad
  `page` still `400`s on its own);
- a key the resolved primary resource declares;
- a [negotiated profile](relationships.md)'s keyword — the Relationship Queries
  profile's `relatedQuery`/`rQ` and the Countable profile's `withCount`
  are recognized only when the client negotiated the relevant profile, so addressing
  one without negotiation now `400`s rather than being ignored;
- any param an app registers via `Server::withCustomQueryParameter()`.

Anything else is a `400` (`QUERY_PARAM_UNRECOGNIZED`, `source.parameter` = the
offending base name). This aligns with the spec, which **mandates** a `400` for a
query param that follows neither the reserved-family rules nor the
custom-param naming rules and that the server does not recognize, and **permits** a
`400` for a well-named custom param the server does not support — strict mode takes
the `400`.

```yaml
json_api:
    strict_query_parameters: false   # restore the old silent-ignore behaviour
```

Set it to `false` to opt out (e.g. while migrating a client that sends stray
params). Note that core's always-on spec baseline still rejects an *all-lowercase*
custom param (one with no non-`a-z` character, like `?bogus`) regardless of this
toggle — the spec requires it. The toggle governs the **strict superset**: a
*well-named* unsupported custom param (one carrying an uppercase letter or other
non-`a-z` character) is `400`'d when strict and ignored when relaxed.

#### Unknown sparse-fieldset members

The same gate also rejects an unknown **`fields[type]` member**. A sparse fieldset
such as `?fields[articles]=title,bogus` previously dropped `bogus` silently and
returned a wrong-but-`200` document; with `strict_query_parameters` on (the default)
a member that names **no declared field** of a known resource type is now a `400`
(`FIELDSET_MEMBER_UNRECOGNIZED`, `source.parameter` = `fields`, the message naming
the offending member) — mirroring how an unknown `?include` path already `400`s. The
check covers **every** named `fields[type]`, including the types of `?include`d
related resources, so a typo in an included type's fieldset is caught too.

The recognized member set is a resource's **full declared field namespace** — every
attribute and relationship name it declares, *including* `id`, hidden, write-only and
[non-sparse](resources.md) fields. So a member is "unknown" only when it names no
declared field at all (a real typo): naming a hidden field is tolerated (a hidden
name and a bogus name behave identically, so there is no information leak), as is `id`
or a non-sparse field. A `fields[type]` for a type the server cannot resolve (an
unregistered type) is left alone — only unknown *members* of *known* types are
rejected. Setting `strict_query_parameters: false` stands this member check down too,
restoring the silent-drop behaviour.

## Response headers (caching and deprecation)

Two cross-cutting HTTP-response concerns are **declarative** (ADR 0054): an
RFC-7234 HTTP cache policy for a type's reads, and a deprecation/sunset signal (the
IETF `Deprecation` header field plus the RFC-8594 `Sunset` header). Both are emitted
by one route-scoped `kernel.response` listener that reads
the resource attribute (falling back to the global `json_api.defaults`) — you never
mutate the response in an after-hook for these.

### Cache headers (G7)

Declare cache directives on `#[AsJsonApiResource(cacheHeaders: …)]`. The map keys
are `max_age`, `s_maxage` (the shared/CDN lifetime), `public`/`private`,
`no_cache`, `must_revalidate`, and `vary` (a list of response-header names added to
`Vary`). An optional nested `operations` key overrides them per read shape —
`collection`, `read`, `related`, `relationship`:

```php
#[AsJsonApiResource(cacheHeaders: [
    'max_age' => 60,
    'public'  => true,
    'vary'    => ['Accept'],
    'operations' => [
        'collection' => ['max_age' => 30],   // the collection caches for less
    ],
])]
final class TrackResource extends AbstractResource { /* … */ }
```

They map onto `Cache-Control` + `Vary` and are applied **only to a successful
`GET`** — never a write (`POST`/`PATCH`/`DELETE`) and never an error document
(caching either is wrong). A resource that declares no `cacheHeaders` (and has no
`json_api.defaults.cache_headers`) gets no `Cache-Control` at all — unchanged
behaviour. The global default applies to a resource that declares none; a
resource-level value **merges over** the default directive-by-directive (your
`max_age` wins, an unset directive inherits the default), and a per-operation
override merges over the resource-level value. An app that set `Cache-Control`
itself (e.g. in an after-hook) is never clobbered.

```yaml
json_api:
    defaults:
        cache_headers:
            max_age: 60
            public: true
            vary: ['Accept']
```

### Deprecation + Sunset (G16)

Declare a deprecation on `#[AsJsonApiResource(deprecation: …, sunset: …,
sunsetLink: …)]`. `deprecation` is `true` (emit a bare `Deprecation: true`) or a
date string (`Deprecation: <date>`); `sunset` is an HTTP-date (`Sunset: <date>`);
when `sunsetLink` is set, a companion `Link: <uri>; rel="sunset"` is emitted too:

```php
#[AsJsonApiResource(
    deprecation: true,
    sunset: 'Sat, 31 Dec 2050 23:59:59 GMT',
    sunsetLink: 'https://music.example/deprecations/tracks',
)]
final class LegacyTrackResource extends AbstractResource { /* … */ }
```

The `Deprecation` header is the IETF Deprecation header field
(`draft-ietf-httpapi-deprecation-header`); `Sunset` and the `sunset` link relation
are RFC 8594. The bundle passes both date values through **verbatim**, so format
them for whichever draft revision your consumers expect — the latest Deprecation
draft wants a structured-field date such as `@1688169599`.

Unlike caching, deprecation/sunset ride **every** response for the type — reads
**and** writes — because a deprecated endpoint is deprecated regardless of method.
The same `json_api.defaults.deprecation` / `sunset` / `sunset_link` keys supply a
fleet-wide default a resource overrides. An explicit `Deprecation`/`Sunset` header
your app already set is never clobbered.

## Named servers (`json_api.servers`)

The architecture is N-server-capable but single-server optimized (ADR 0034). The
top-level `base_uri`/`version` define the implicit **`default`** server, so most apps
need no `servers:` block at all. When you genuinely run more than one API surface —
say a public catalog and an internal admin API with a different base URI — declare the
extra surfaces under `servers:`:

```yaml
json_api:
    base_uri: 'https://music.example'
    servers:
        admin:
            base_uri: 'https://admin.music.example'
```

Each named server **inherits the top-level value when its own is omitted** — the
`admin` server above declares only `base_uri` and inherits `version: '1.1'`. Every
declared server (including `default`) gets one `ServerFactory`, registered under the
id `haddowg.json_api.server_factory.<name>`.

**Reserved name.** A named server may **not** be literally `default` — that name
belongs to the implicit top-level server. Declaring it is a build-time
`LogicException`:

```
The JSON:API server name "default" is reserved for the implicit server defined by
the top-level base_uri/version; declare other servers under different names.
```

Configuration only *declares* servers. The rest of the multi-server story lives on
three other pages, cross-linked so they don't drift:

- **Assignment** — which types join which server — is the `server:` argument on the
  resource attribute (`#[AsJsonApiResource(server: 'admin')]`, or a list for a shared
  type). See [resources](resources.md).
- **Route mounting** — one per-server import per surface (`resource: admin`, under a
  `prefix:`) — lives in your `routes/json_api.yaml`. See [routing](routing.md).
- **End-to-end resolution** — how a request reaches its own server's `ServerFactory`
  and renders that server's `base_uri` in links — is on
  [multi-server and testing](multi-server-and-testing.md).

## Optional dependencies

The bundle's hard runtime dependencies are minimal (see [installation](install.md)).
Everything beyond the read/write core is opt-in via a `suggest` dependency. Each one
**degrades gracefully when absent — but the degradation differs**, and two of them
are silent, so read this table before assuming a capability is active.

| Package | Enables | When absent |
| --- | --- | --- |
| `doctrine/orm` | The reference Doctrine provider/persister ([doctrine](doctrine.md)) | No Doctrine path; `#[AsJsonApiResource(entity:)]` mappings are inert. Provide your own [data provider](custom-data-providers.md). |
| `symfony/validator` | The constraint bridge ([validation](validation.md)) | **Writes run unvalidated, silently** — declared constraints are not enforced. |
| `symfony/event-dispatcher` | The lifecycle-hook seam ([lifecycle hooks](lifecycle-hooks.md)) | The per-operation events and resource hook methods are inert — the dispatcher is injected `->nullOnInvalid()` and the seam is simply off. |
| `symfony/security-core` + `symfony/expression-language` | Declarative resource authorization ([authorization](authorization.md)) | The `security:`/`securityCreate:`/… expressions on `#[AsJsonApiResource]` are inert — both packages must be present for the security subscriber to register. (Enforcement also needs a configured firewall; absent one the subscriber is a no-op.) |
| `symfony/doctrine-bridge` | The `UniqueEntity` entity-level rule | `UniqueEntity` cannot be translated. Usually present transitively via `doctrine/doctrine-bundle`. |
| `egulias/email-validator` | Strict (RFC 5322) email validation (`EmailFormat(strict)`) | **Strict silently degrades** to Symfony's HTML5 email mode. |
| `opis/json-schema` | The structural document linter (`schema_validation`) | Enabling `schema_validation: true` without it **fails the build**. |
| `symfony/intl` | Sourcing a reference-data type (e.g. `countries`) from the ICU dataset | The example app's `countries` type — a standalone `CountrySerializer` + `CountryProvider`, no entity — has no data source. |

The two **silent** degradations are the ones to internalise: without
`symfony/validator`, `CrudOperationHandler`'s validator resolves to null and writes
are accepted without checking your constraints; and `EmailFormat(strict)` quietly
falls back to HTML5 validation without `egulias/email-validator`. Neither raises an
error — see [validation](validation.md) for the full bridge behaviour.

`symfony/doctrine-bridge` is the subtle one: it ships Symfony's `UniqueEntity`
constraint and validator that the bundle's `UniqueEntity` VO translates to, and it is
typically only present *transitively* through `doctrine/doctrine-bundle`. The bundle
lists it in its own [`composer.json`](../composer.json) `suggest` so the requirement
is discoverable; the example app depends on it directly. See the `UniqueEntity`
section of [validation](validation.md).

These `suggest` entries are declared in the bundle's
[`composer.json`](../composer.json) and mirrored in the
[example app's `composer.json`](../examples/music-catalog-symfony/composer.json).

## Why this page is short

Almost nothing in the bundle is configured through `json_api:`. A resource is
discovered because its service is tagged by autoconfiguration; a custom data provider
shadows Doctrine because it's tagged at a higher priority; a constraint translator
registers because it implements an autoconfigured interface. The tag/priority model —
not config — is how you compose and override behaviour:

- The discovery and capability tags (`RESOURCE_TAG`, `SERIALIZER_TAG`, …) →
  [capability composition](capability-composition.md).
- The data-layer tags (`DATA_PROVIDER_TAG`, `DATA_PERSISTER_TAG`,
  `DOCTRINE_EXTENSION_TAG`) and their priority/first-match resolution →
  [the data layer](data-layer.md) and [custom data providers](custom-data-providers.md).
- The validation tag (`CONSTRAINT_TRANSLATOR_TAG`) → [validation](validation.md).

If you find yourself looking for a config key to turn something on, it's almost
certainly a tag instead.

## Next / See also

- [Resources and `#[AsJsonApiResource]`](resources.md) — including the `server:`
  assignment argument.
- [Routing](routing.md) — the per-server route import and the operation allow-list.
- [Multi-server and testing](multi-server-and-testing.md) — end-to-end server
  resolution.
- [Validation](validation.md) — what `symfony/validator` (and the optional linter)
  buys you.
- Core [server.md](https://github.com/haddowg/json-api/blob/main/docs/server.md) and
  [schema-validation.md](https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md).
