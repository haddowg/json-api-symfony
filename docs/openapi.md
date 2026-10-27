# OpenAPI & documentation

The bundle generates a faithful, rich **OpenAPI 3.1** description of your JSON:API
surface directly from the metadata you already declare — your resources, fields,
constraints, relationships, filters, sorts, paginators, custom actions and security
expressions. There is no second source of truth to keep in sync: the document is
*projected* from the live registry, served live, exportable for CI, and — crucially —
**provably describes the responses you actually serve** (see [conformance](#conformance-proving-the-document-describes-reality)).

Because the projection reads first-class JSON:API metadata (not a generic
PHP-attribute scrape), it can express the JSON:API document shape precisely: typed
attribute schemas from your constraints, linkage and compound-document envelopes,
enumerated `filter[…]` / `sort` / `include` / `fields[…]` / `page[…]` parameters, and the
standard error-status set per operation.

## Per-operation response declarations

By default each operation advertises one success response — `POST` → `201`,
`PATCH` → `200`, `DELETE` → `204`, `GET` → `200`. A resource overrides the set per
operation with the
[`create`/`update`/`delete`/`fetchOne`/`fetchCollection` params](resources.md#per-operation-response-declarations)
on `#[AsJsonApiResource]`, and a [custom action](actions.md#advertising-the-output-in-openapi)
with its `responds` set. This is how the **asynchronous-write lifecycle**
([async writes](async.md)) becomes part of the contract:

- `new Accepted('export-jobs')` → a `202 Accepted` whose body is the `export-jobs`
  document, with `Content-Location` (the poll URL) and `Retry-After` headers.
- `new SeeOther()` on `fetchOne` → a `303 See Other` with a `Location` header (the
  completed job redirecting to the produced resource), no body.
- `new NoContent()` → a `204`; `new MetaResult()` → a `200` meta-only document.

So a codegen client sees, from the document alone, that `POST /catalog-exports`
returns `202` pointing at an `export-jobs` resource, and that `GET /export-jobs/{id}`
may `303` to the finished export. These responses ride core's shared projection, so
the Laravel package's document for the same domain is **byte-identical**.

## Enabling generation

OpenAPI generation is on by default. The HTTP routes that serve it are gated for
safety (see [exposure](#exposure-the-docs-routes)), but the document is always buildable
via the [CLI](#exporting-from-the-cli).

```yaml
# config/packages/json_api.yaml
json_api:
  base_uri: 'https://api.example.com'
  version: '1.1'
  openapi:
    enabled: true            # generation available at all (CLI always works)
    info:
      title: 'My API'
      version: '1.0.0'
      description: 'A JSON:API surface.'
```

Import the docs routes once, alongside your resource routes:

```yaml
# config/routes/json_api.yaml
json_api:
  resource: .
  type: jsonapi

json_api_openapi:
  resource: .
  type: jsonapi_openapi   # mounts /docs.json (+ /{server}/docs.json) and /docs
```

## The routes

| Route | Path | Serves |
|-------|------|--------|
| Document (default server) | `GET /docs.json` | the OpenAPI 3.1 JSON document |
| Document (named server) | `GET /{server}/docs.json` | that server's document (per-server mode) |
| Viewer | `GET /docs` | a Swagger UI **or** ReDoc page rendering the document |
| JSON Schemas (default server) | `GET /schemas.json` | the per-type [JSON Schemas](#serving-the-json-schemas), keyed by type |
| JSON Schemas (named server) | `GET /{server}/schemas.json` | that server's schemas (per-server mode) |

All paths are configurable (`json_api.openapi.json.path`, `…ui.path`,
`…json_schema.path`). The document and the schemas are served as `application/json`;
they are **not** JSON:API routes, so they carry no `application/vnd.api+json`
negotiation.

> **Try it live.** The `examples/music-catalog-symfony` app serves these routes. Run
> `docker compose up` in that directory, then open **<http://localhost:8080/docs>** for
> the Swagger UI (raw document at `/docs.json`; the `admin` server's document at
> `/admin/docs.json`).

### Exposure: the docs routes

The HTTP routes are **auto-exposed when `kernel.debug`** is true (your dev environment)
and **off in production** unless you opt in. The CLI export is always available.

```yaml
json_api:
  openapi:
    expose_in_prod: true   # serve /docs.json + /docs + /schemas.json outside kernel.debug too
```

The viewer route additionally honours `ui.enabled`, and the JSON Schema routes honour
`json_schema.enabled`, so you can serve the raw document in prod while keeping the human
viewer (or the schemas) dev-only — or any other combination.

### Profile-gated parameters: `x-profile`

Some query parameters are recognised by the runtime **only when a JSON:API
[profile](https://jsonapi.org/format/#profiles) is negotiated** on the request (its
URI supplied via the `profile` media-type parameter of `Accept` /
`Content-Type`). `withCount` is the built-in case — the count tokens are honoured
only under the Countable profile.

The served document advertises this constraint honestly: every such parameter
carries an **`x-profile`** vendor extension whose value is the profile URI that must
be negotiated for the parameter to take effect. A client (or a codegen tool) that
does not negotiate that profile should treat the parameter as inert. For example, the
`withCount` parameter on a countable collection is emitted as:

```json
{
  "name": "withCount",
  "in": "query",
  "schema": { "type": "array", "items": { "type": "string", "enum": ["_self_", "tracks"] } },
  "style": "form",
  "explode": false,
  "x-profile": "https://haddowg.github.io/json-api/profiles/countable/"
}
```

The extension is produced by the core projection (so it is present on every served
build path — the warmer, the controllers, and the CLI export alike); the bundle
simply serves the projected document verbatim.

## Links and `describedby`

The links the server builds (each resource's `self`, relationship `self` and `related`,
the top-level `self`, pagination links and [action links](actions.md#exposing-an-action-as-a-resource-link-aslink))
render as JSON:API link objects. When the document is served, each one carries a
`describedby` pointing at the operation in the document that describes its target, and
the document gets a top-level `describedby` naming the operation that produced it:

```jsonc
// GET /api/articles/1, with the routes imported under /api
{
  "data": {
    "type": "articles",
    "id": "1",
    "links": {
      "self": {
        "href": "https://example.test/api/articles/1",
        "describedby": "/api/docs.json#/paths/~1articles~1%7Bid%7D/get"
      },
      "publish": {
        "href": "https://example.test/api/articles/1/-actions/publish",
        "describedby": "/api/docs.json#/paths/~1articles~1%7Bid%7D~1-actions~1publish/post"
      }
    },
    "relationships": {
      "author": {
        "links": {
          "self": {
            "href": "https://example.test/api/articles/1/relationships/author",
            "describedby": "/api/docs.json#/paths/~1articles~1%7Bid%7D~1relationships~1author/get"
          },
          "related": {
            "href": "https://example.test/api/articles/1/author",
            "describedby": "/api/docs.json#/paths/~1articles~1%7Bid%7D~1author/get"
          }
        }
      }
    }
  },
  "links": {
    "self": {
      "href": "https://example.test/api/articles/1",
      "describedby": "/api/docs.json#/paths/~1articles~1%7Bid%7D/get"
    },
    "describedby": { "href": "/api/docs.json#/paths/~1articles~1%7Bid%7D/get" }
  }
}
```

The fragment is a JSON Pointer to the path and method in `paths`. An action link names
the action's first declared method. A link whose operation the document doesn't carry
gets no `describedby`, so none points at a fragment that isn't there.

The document URL is the path of the server's document route, host-relative and with the
prefix the routes are imported under: `/docs.json` for the default server and
`/{server}/docs.json` for a named one, or `/docs.json` for every server in combined mode.
When the document lives somewhere else, such as a docs site or a CDN serving the
[`public_path`](#production-the-cache-warmer) files, name it with `document_url`. It's
used as given, with `{server}` replaced by the server name (or `combined` in combined
mode), the same names the `public_path` files use:

```yaml
json_api:
  openapi:
    document_url: 'https://docs.music.example/openapi/{server}.json'
```

The bundle picks the document URL for a server in this order:

1. `document_url`, when it's set.
2. The bundle's own document route, `jsonapi.openapi.default` or `jsonapi.openapi.server`.
3. Any other route whose controller is `OpenApiController`, for an app that routes the
   document itself rather than importing the `jsonapi_openapi` routes. The controller can
   be written as the class, `OpenApiController::class . '::__invoke'` or the array form.
   The route serves the server named by its `server` default, the default server when it
   has none, or every server its `{server}` requirement accepts. A route that names the
   server wins over a parametric one. In combined mode any such route serves every server.

```php
// config/routes.php
use haddowg\JsonApiBundle\Controller\OpenApiController;

$routes->add('api_v5_docs', '/api/v5/docs.json')
    ->controller(OpenApiController::class)
    ->defaults(['server' => 'v5']);
```

The third step reads the whole route collection, so it doesn't run per request. The
warmer scans the routes at `cache:warmup` and writes the result beside the documents.
Without one, the first request that needs it scans once and writes it; in debug the scan
is kept only for the process, so a route you add is picked up without clearing the cache.

When `describedby` is on, no `document_url` is set, the expose gate is open, and no route
serves a server's document, the warmup logs a warning naming the server and suggesting
`document_url` or a document route. Links for that server carry no `describedby`, and
nothing fails, since `describedby` is optional. A closed expose gate is a deliberate
choice, so it gets no warning. Only a server that serves JSON:API routes is checked: one
whose routes you never import, such as an implicit `default` server in an app that
declares only named servers, renders no links and gets no warning.

Links get no `describedby` when the document isn't served and no `document_url` is set
(the expose gate is closed, or generation is disabled), or when `describedby: false`.
They stay link objects either way. To render bare URL strings instead, set
`json_api.link_objects: false`, or the same key on a [named server](configuration.md#named-servers-json_apiservers).
A string can't carry a `describedby`, but the document's own top-level one still renders.

An [Atomic Operations](atomic-operations.md) result's top-level `describedby` names the
atomic endpoint's `POST` (`/docs.json#/paths/~1operations/post` with the default
`atomic_operations.path`), and the resource objects inside it carry the same links as
anywhere else. Error documents get none.

A `describedby` set on the response with `withDescribedby()`, or one inside its
`withLinks()`, replaces the per-operation one, so set it only when a response should
point somewhere else.

The generated document describes these links: each `<Type>ResourceLinks`,
`<Type>DocumentLinks`, `<Type>CollectionLinks` and `<Type><Rel>RelationshipLinks`
component lists the members that context renders, with an example carrying the same
`describedby`, and an `asLink` action is listed by its name.

A resource's [declared links](resources.md#declared-links) are named, optional properties
of its `<Type>ResourceLinks`. A bare link takes the open `LinkObject` schema; a link
object gets its own schema with `href`, each static member as a `const`, and a `meta`
object built from the declared members the way attributes are. The operation examples
carry each link, built from its `example()` and its meta members' examples, and a missing
one is reported like a missing attribute example, as `links.<name>` or
`links.<name>.meta.<member>`. A document whose types declare a link is stamped contract
14 in `info.x-generator`; one without stays on the baseline. Core's
[OpenAPI page](https://github.com/haddowg/json-api/blob/main/docs/openapi.md#links-and-describedby)
shows the full schema.

The warmer writes a small map of each document's operations beside it, which is all a
request reads to resolve a `describedby`. Without a warmed map (in development, say) the
first request builds the document once and writes the map.

!!! note
    A `describedby` points into the document as the warmer built it. A
    [per-request decorator](#per-request-customisation) that removes an operation for some
    requests doesn't remove the `describedby` pointing at it, so a client served the
    trimmed document can be handed a fragment it doesn't contain.

## The viewer

A single config-driven route renders the document with **Swagger UI** or **ReDoc**
(one, not both), as plain CDN-linked HTML — no Twig dependency, no asset build step.

```yaml
json_api:
  openapi:
    ui:
      enabled: true
      renderer: swagger    # swagger | redoc
      path: /docs
      cdn: ~               # override the pinned CDN origin (self-host / air-gap)
      redoc_version: 3.0.1 # the exact ReDoc release; a 2.x one such as 2.5.4 also works
      redoc_telemetry: false
```

ReDoc defaults to 3.0.1. Its standalone bundle is an ES module, so the page loads it
with `<script type="module">` and it starts itself from the `<redoc spec-url>` tag.
ReDoc 3 sends usage telemetry unless told not to, so the page renders
`disable-telemetry="true"` on that tag. Set `redoc_telemetry: true` to leave the
attribute off and let ReDoc send it.

`redoc_version` takes an exact release, never a range. A 2.x release such as `2.5.4`
(the `OpenApiUiController::REDOC_VERSION_2` constant) switches the page to the 2.x
loader, a classic `<script>` that defines the `Redoc` global. ReDoc 2 has no telemetry,
so `redoc_telemetry` does nothing there. Any other value fails the container build.

The page loads Swagger UI / ReDoc from a pinned public CDN (jsDelivr) and points it at
the document route, generated via the router (so the spec URL honours any routing prefix
you mount the imported routes under — `->prefix('/api')` — as well as the front-controller
script base; the viewer and the document it points at always share the same mount). For an
**air-gapped** deployment, mirror the assets
and set `ui.cdn` to your origin; document a matching `script-src` / `style-src` CSP
allowance for whichever origin you use.

`ui.cdn` replaces the directory the assets load from. For Swagger UI that's the
`swagger-ui-dist` package root. For ReDoc it's the package's `bundles/` directory, the
one holding `redoc.standalone.js`, for either major: the page loads
`<cdn>/redoc.standalone.js`. Mirror the release `redoc_version` names. A 3.x bundle is a
module script, so the server must send it with a JavaScript `Content-Type`, and from
another origin it also needs an `Access-Control-Allow-Origin` header, since browsers
fetch module scripts with CORS.

To replace the viewer wholesale, register your own controller on `ui.path` — because your
app imports the docs route loader, your own `GET /docs` route wins by registration order.

## Production: the cache warmer

The document is **never built per request** (a [serve decorator](#per-request-customisation)
adjusts the pre-built one). A `CacheWarmer` pre-builds each server's
document, the per-type JSON Schemas, and the aggregate schema document at `cache:warmup`
— i.e. on every deploy — into `%kernel.cache_dir%`; the controllers then serve the
pre-built artifacts with an `O(file read)`. In dev (`kernel.debug`) the controllers
lazy-build and cache on demand, since resources change between edits.

The warmer is **optional and non-fatal**: a documentation-generation failure never breaks
a deploy (it logs and the controller's lazy build is the safety net). To also emit a
fully static file a web server / CDN can serve with zero PHP:

```yaml
json_api:
  openapi:
    public_path: '%kernel.project_dir%/public/openapi'   # writes <server>.json (+ .yaml + <server>.schemas.json) at cache:warmup
```

## Serving the JSON Schemas

Each type's resource object is also published as a standalone **JSON Schema 2020-12**
document — the same projection the OpenAPI components use, wrapped with the `$schema`
dialect keyword and an addressable `$id` so it validates on its own. They are served
over HTTP as a single **aggregate** keyed by JSON:API type at `GET /schemas.json`
(and `GET /{server}/schemas.json` per named server) — one fetch a client generator
consumes to drive an opt-in request/response validation seam:

```json
{
  "albums":  { "$schema": "https://json-schema.org/draft/2020-12/schema", "$id": "urn:jsonapi:schema:albums",  "type": "object", "properties": { "type": { "const": "albums" }, ... } },
  "tracks":  { "$schema": "...", "$id": "urn:jsonapi:schema:tracks",  ... }
}
```

The routes ride the same expose gate as the document, with their own toggle and path:

```yaml
json_api:
  openapi:
    json_schema:
      enabled: true          # register GET /schemas.json (+ /{server}/schemas.json); default true
      path: /schemas.json    # the default server's aggregate path
```

In **combined** multi-server mode `GET /schemas.json` serves one aggregate spanning
every server. The same schemas are available without HTTP exposure from the
[CLI](#exporting-from-the-cli) (`json-api:json-schema:export`).

## Exporting from the CLI

Two commands publish the document and the standalone schemas with no HTTP exposure — for
CI spec-diffing, publishing and codegen. They establish the bundle's `json-api:` command
namespace.

```bash
# The OpenAPI document (JSON to stdout, or a file; YAML needs symfony/yaml):
php bin/console json-api:openapi:export
php bin/console json-api:openapi:export --server=admin --format=yaml --output=openapi.yaml
php bin/console json-api:openapi:export --strict > openapi.json   # fail on a missing example or undeclared scope

# Standalone per-type JSON Schema 2020-12 documents:
php bin/console json-api:json-schema:export --type=articles            # one type → stdout
php bin/console json-api:json-schema:export --output=build/schemas/    # every type → directory
```

The standalone JSON Schemas are projected by the **same** core projector the OpenAPI
document uses, so a standalone `<type>.json` and the in-document `<Type>Resource`
component agree.

The OpenAPI export lists every [operation example](#operation-examples) it could not
build as a warning on stderr, one line per missing value, so the document on stdout stays
valid to pipe. `--strict` turns any of them into a non-zero exit and writes no document,
which suits a CI step that guards the examples. The served document and the cache warmer
never report them.

It also warns about each [OAuth scope](scopes.md#the-openapi-document) an operation needs
that neither your scope catalogue nor the scope scheme's flows describe, and fails on them
under `--strict`. A scope scheme that isn't `oauth2` is a warning only.

## Customisation

### Inline authoring (descriptions & examples)

Add `->describedAs()` and `->example()` to any field, relation or filter builder; the
projection surfaces them on the corresponding schema / parameter.

```php
use haddowg\JsonApi\Resource\Field\Str;

Str::make('title')
    ->required()->minLength(3)->maxLength(120)
    ->describedAs('The article headline, shown in listings.')
    ->example('JSON:API in PHP');
```

The constraints you already declare (`minLength`, `pattern`, `in`, `min`/`max`, …) map
**1:1** to JSON Schema 2020-12 keywords — that fidelity is the whole reason the target is
3.1. The few constraints with no lossless keyword (`When` with a dynamic condition,
`CompareField`, the `After`/`Before`/`Between` date bounds) degrade to a human-readable
note in the schema `description` rather than emitting a wrong schema.

### Operation examples

Every operation's request body and success responses, and each shared error response,
carry a whole worked `example` built from the `->example()` values your fields declare
(core's [operation examples](https://github.com/haddowg/json-api/blob/main/docs/openapi.md#operation-examples)).
Nothing is invented: an operation that needs a value with no declared example gets no
example, and `json-api:openapi:export` names the type and field it was missing. Declare
an `Id` example too, since every resource object and linkage needs one.

Linkage to a type the server doesn't register has no `Id` example to borrow, so give the
relation an `exampleId()` (see [relationships](relationships.md#the-related-endpoints-target-must-be-registered-on-the-server)).
A collection whose paginator counts on every request, one built with `withCount()`, shows
`meta.total`, `meta.page.total` and the `last` link as it is served. The bundle reads that
from the same paginator the endpoint uses: the relation's own, then the related
resource's `pagination()`, then the server default. A cursor page never counts.

`operationExamples` on `#[AsJsonApiResource]` (or `#[AsJsonApiSerializer]`) replaces or
switches off an operation's example. Keys are core's operation names: `fetchCollection`,
`fetchOne`, `create`, `update`, `delete`, and for a relation `fetchRelated.<relation>`,
`fetchRelationship.<relation>`, `updateRelationship.<relation>`,
`addRelationship.<relation>` and `removeRelationship.<relation>`. A core
`OperationExample` replaces the request and the responses it names and leaves the rest
generated; `false` emits none:

```php
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(operationExamples: [
    'fetchOne' => new OperationExample(responses: [200 => ['data' => ['type' => 'playlists', 'id' => '1', 'attributes' => ['title' => 'Road trip']]]]),
    'update' => false,
])]
final class PlaylistResource extends AbstractResource { /* … */ }
```

An unknown key, or a CRUD operation the type doesn't expose, fails the container build;
a relation the type doesn't have fails the projection. A hand-written serializer declares
no fields, so its operations have nothing to build from; switch them off with `false` or
write them out.

A custom action takes one `example` on `#[AsJsonApiAction]`, with the same meaning, which
is the way to show a meta document:

```php
#[AsJsonApiAction(
    type: 'albums',
    path: 'summary',
    scope: ActionScope::Collection,
    responds: [new MetaResult()],
    example: new OperationExample(responses: [200 => ['meta' => ['albums' => ['total' => 15]]]]),
)]
```

Example documents are written as PHP arrays and stored in the compiled container as
JSON, so an empty array always renders as `[]`. `json_api.openapi.examples: false` turns
generation off for every server, and a named server's own `openapi_examples` overrides
it.

### Describing types, operations & relationships

Every documentable element has a **generated default description**, and every one of
them is **overridable** declaratively (never by reaching into the document with a
decorator). There are three override surfaces:

- a **type's resource-object schema** — `#[AsJsonApiResource(description: …)]`, or the
  resource's `getDescription(): ?string` method hook;
- a **CRUD operation** (collection / fetch-one / create / update / delete) —
  `#[AsJsonApiResource(operationDescriptions: […])]` keyed by the `Operation` case name,
  or the resource's `describeOperation(OperationType $op): ?string` method hook;
- a **relationship**'s related + relationship operations — `->describedAs(…)` on the
  relation field (the one description applies to every endpoint of that relationship).

```php
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(
    description: 'A sellable product in the catalog.',
    operationDescriptions: [
        Operation::FetchCollection->name => 'Browse the product catalog.',
    ],
)]
final class ProductResource extends AbstractResource
{
    public function fields(): array
    {
        return [
            // … the relation description flows onto GET /products/{id}/category
            //    and GET /products/{id}/relationships/category.
            BelongsTo::make('category', 'categories')
                ->describedAs('The catalog category this product belongs to.'),
        ];
    }

    // Equivalent to the attribute fields above; useful when the text is computed.
    public function getDescription(): ?string
    {
        return 'A sellable product in the catalog.';
    }

    public function describeOperation(OperationType $op): ?string
    {
        return $op === OperationType::Delete ? 'Permanently retires a product.' : null;
    }
}
```

Precedence is **method hook → attribute → generated default**: when a resource declares
both, the method hook wins (it is the more specific, runtime surface). Returning `null`
(the method default) or omitting the attribute key leaves the generated default in place.
An unknown `operationDescriptions` key fails the build with a clear error.

The generated summaries and descriptions name one resource with the singular of its type
name and the right article, so `agencies` reads "Fetch an agency" and `export-jobs`
"Fetch an export job". Collections keep the plural ("List agencies"). When the
inflection reads badly for your type, name the singular yourself, with
`#[AsJsonApiResource(singular: 'media item')]` or the resource's
`getSingularName(): ?string` hook. Give the noun without an article; the generator picks
"a" or "an". The same precedence applies, the method hook first, and a blank `singular:`
fails the build.

```php
#[AsJsonApiResource(singular: 'media item')]
final class MediaResource extends AbstractResource
{
    public static string $type = 'media';
}
```

### Vendor extensions

`#[AsJsonApiResource(extensions: […], operationExtensions: […])]` adds OpenAPI `x-`
extensions to a type's operations, for example to tell a client generator which feature
gates a resource:

```php
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(
    extensions: ['x-feature' => 'agency'],
    operationExtensions: [
        Operation::Delete->name => ['x-feature' => 'agency-admin', 'x-rate-limit' => 10],
    ],
)]
final class AgencyResource extends AbstractResource { /* … */ }
```

`extensions` goes on every operation the document emits for the type: the five CRUD
operations, every related and relationship endpoint, and the type's custom actions.
`operationExtensions` is keyed by the `Operation` case name and merged over `extensions`
for that CRUD operation, so `DELETE /agencies/{id}` above carries
`x-feature: agency-admin` and `x-rate-limit: 10`, and every other operation carries
`x-feature: agency`. A type that declares neither renders exactly as before.

Every key must be a full extension name starting with `x-`. A bare name such as
`feature`, `x-` on its own, a name under the OAS-reserved `x-oai-`/`x-oas-` prefixes, or
an unknown operation key fails the container build, and so does an `operationExtensions`
entry for an operation the type doesn't expose (outside `operations`, or a write on a
`readOnly: true` resource). Values can be any JSON-encodable
scalar or array. There is no method hook for extensions, so when the value has to be
computed, add it with a [decorator](#wholesale-customisation-the-decorator).

A custom action can carry its own on `#[AsJsonApiAction(extensions: […])]`, merged over the
type's set for that action's operation; see
[declaring how safe an action is](actions.md#declaring-how-safe-an-action-is).

A [standalone serializer](capability-composition.md) declares them the same way on
`#[AsJsonApiSerializer]`. Its exposed operations are its `operations` list, so a
serialize-only type can only declare `extensions`. Those still reach any custom action
mounted on the type:

```php
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;

#[AsJsonApiSerializer(
    type: 'charts',
    operations: [Operation::FetchCollection, Operation::FetchOne],
    extensions: ['x-feature' => 'charts'],
    operationExtensions: [Operation::FetchCollection->name => ['x-cache' => 'long']],
)]
final class ChartSerializer extends AbstractSerializer { /* … */ }
```

### Tagging (grouping operations)

OAS tags drive how Swagger UI / ReDoc group operations. Each resource's operations are
grouped under one tag; the default is the humanized, title-cased type (`blog-post` →
`Blog Posts`). Override per resource and per action:

```php
#[AsJsonApiResource(tags: ['Catalog'])]
final class ProductResource extends AbstractResource { /* … */ }

#[AsJsonApiAction(tags: ['Catalog'])]
final class RecalculatePrices implements ActionHandlerInterface { /* … */ }
```

An action with no `tags` inherits the resource tag of the type it mounts on. When the
type is served by a different resource class on each server, each server's document
uses that server's class's tags, for the resource and for its untagged actions. The
top-level tag **definitions** (description, externalDocs, order) are config-authoritative;
any tag referenced but not defined is auto-synthesized (name only):

```yaml
json_api:
  openapi:
    tags:
      - name: Catalog
        description: 'Products and catalog operations'
        externalDocs: { url: 'https://docs.example.com/catalog' }
```

### Enum descriptions

An `In`-constrained attribute sourced from a **backed enum** becomes a reusable named
component (`#/components/schemas/<Enum>`), `$ref`'d everywhere it is used. Declare
per-value descriptions with the core enum trio:

```php
use haddowg\JsonApi\Resource\Enum\DescribedEnum;
use haddowg\JsonApi\Resource\Enum\DescribesEnumCases;
use haddowg\JsonApi\Resource\Enum\EnumCaseDescription;

enum CatalogStatus: string implements DescribedEnum
{
    use DescribesEnumCases;

    #[EnumCaseDescription('Not yet visible in the catalog')] case Draft = 'draft';
    #[EnumCaseDescription('Live and listed')]                case Published = 'published';
    #[EnumCaseDescription('Withdrawn from sale')]            case Archived = 'archived';
}
```

```php
Str::make('status')->enum(CatalogStatus::class);
```

The descriptions are emitted **both** as a markdown `value → description` table in the
schema `description` (the only form the free CDN renderers show) **and** as
`x-enum-varnames` / `x-enum-descriptions` vendor extensions (codegen-portable). Switch
with `enum_value_descriptions: both | extensions | description` (default `both`).

### Security schemes

Declare named security schemes; operations carrying a `security*` expression get the
configured requirement. The authz expression itself is never parsed for scheme semantics
— you declare the scheme, the projection attaches it.

```yaml
json_api:
  openapi:
    security:
      schemes:
        bearer: { type: bearer, bearerFormat: JWT }   # bearer = shorthand for http + bearer
        apiKey: { type: apiKey, apiKeyName: X-Api-Key, in: header }
        oidc:   { type: openIdConnect, openIdConnectUrl: 'https://issuer.example.com/.well-known/openid-configuration' }
        oauth:                                         # full oauth2 flows graph
          type: oauth2
          flows:
            authorizationCode:
              authorizationUrl: 'https://auth.example.com/authorize'
              tokenUrl: 'https://auth.example.com/token'
              refreshUrl: 'https://auth.example.com/refresh'   # optional
              scopes:
                'catalog:read': 'Read the catalog'
                'catalog:write': 'Manage the catalog'
            # also: implicit | password | clientCredentials
      default_requirement: [bearer]
```

Every scheme type the OpenAPI 3.1 spec defines is supported — `http`/`bearer`, `apiKey`,
`openIdConnect`, and `oauth2` (with its `implicit` / `password` / `clientCredentials` /
`authorizationCode` flows). A scheme that cannot be built (e.g. an `oauth2` with no flow,
or an `openIdConnect` with no URL) is dropped rather than emitting an invalid document, and
a `default_requirement` naming a scheme that did not resolve is dropped with it (the
document never carries a dangling security reference).

`scope_scheme` (default `oauth2`) names the scheme each operation's [OAuth
scopes](scopes.md) are listed under. It is only read once some operation needs a scope.

```php
#[AsJsonApiResource(securityRead: 'is_granted("ROLE_USER")')]
final class PlaylistResource extends AbstractResource { /* … */ }
```

### Your own error codes

The document catalogues one named schema variant per error code the server can raise,
so a generated client gets a typed exception per code rather than one opaque error
type. Core's codes are always there; point `json_api.error_codes.paths` at the
directory your own described errors live in and they join them. See
[errors](errors.md#documenting-your-own-error-codes).

### Wholesale customisation (the decorator)

For anything the projection can't express declaratively — a server variable, an extra
scheme, per-individual-operation tags, vendor extensions, hand-written examples, or
rewriting any part of the document — implement `OpenApiFactoryInterface`. It is
autoconfigured and applied **after** the projection on every build path (warmer,
controller, CLI), so your decorator gets the last word.

```php
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface;

final class StampInternalServer implements OpenApiFactoryInterface
{
    public function decorate(OpenApi $document, string $server): OpenApi
    {
        return $document->withServers([
            ...$document->servers,
            new \haddowg\JsonApi\OpenApi\Server('https://internal.example.com', 'Internal'),
        ]);
    }
}
```

Register multiple by priority (`#[AsTaggedItem]` or a `priority` tag attribute); lower
priority runs first, the highest-priority decorator gets the final mutation.

### Per-request customisation

`OpenApiFactoryInterface` runs once per deploy, so it can't see who is asking. When one
deployment serves several hosts that each need their own view of the spec (absolute
`servers` URLs, operations a tenant hasn't paid for, example hosts), implement
`OpenApiServeDecoratorInterface` instead. It runs every time `docs.json` is served and
gets the `Request`:

```php
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeDecoratorInterface;
use Symfony\Component\HttpFoundation\Request;

final class ServersFromHost implements OpenApiServeDecoratorInterface, OpenApiServeCacheKeyInterface
{
    public function decorate(OpenApi $document, Request $request, string $server): OpenApi
    {
        return $document->withServers([new Server('https://' . $request->getHost() . '/api/v5')]);
    }

    public function cacheKey(Request $request, string $server): string
    {
        return $request->getHost();
    }
}
```

Hiding operations works well with the `x-feature` [vendor extension](#vendor-extensions):
mark operations at build time, then drop the ones the requesting tenant lacks.

```php
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Operation;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeDecoratorInterface;
use Symfony\Component\HttpFoundation\Request;

final class FeatureGate implements OpenApiServeDecoratorInterface, OpenApiServeCacheKeyInterface
{
    public function __construct(private readonly TenantFeatures $features) {}

    public function decorate(OpenApi $document, Request $request, string $server): OpenApi
    {
        $enabled = $this->features->forHost($request->getHost());
        $items = [];
        foreach ($document->paths->items ?? [] as $path => $item) {
            $items[$path] = $item->withOperations(\array_filter(
                $item->operations,
                static function (Operation $operation) use ($enabled): bool {
                    $feature = $operation->extension('x-feature');

                    return !\is_string($feature) || \in_array($feature, $enabled, true);
                },
            ));
        }

        return $document->withPaths(new Paths($items));
    }

    public function cacheKey(Request $request, string $server): array
    {
        // Bump 'v' whenever decorate() changes behaviour.
        return ['v' => 1, 'features' => $this->features->forHost($request->getHost())];
    }
}
```

Both are autoconfigured and ordered by `priority` like the build-time decorators: lower
runs first, the highest gets the last word. `$server` is the server name, or
`[combined]` in combined mode.

You only remove operations. After the last decorator the bundle cleans up what they
orphaned:

- a path item left with no operation is dropped;
- a component (schema, response, parameter, request body, header and so on) that the
  served document referenced before decoration and nothing references now is dropped,
  following `$ref`s through other components;
- a tag definition whose operations all went is dropped.

Components the document never referenced (or that a decorator added) stay, and so does
everything they reference. Security schemes are never touched. Relationship members
aren't removed either: if `articles` keeps its `author` relationship while the `users`
endpoints are hidden, the article responses still carry that linkage on the wire, so the
`users` schemas it points at stay in the document. Hide a relationship in the document
only when you also hide it at runtime.

The same goes for [`describedby`](#links-and-describedby): links point into the document
as the warmer built it, so a response can carry a `describedby` naming an operation a
serve decorator hid from that client.

`schemas.json` has its own interface, `JsonSchemaServeDecoratorInterface`, which gets
the schemas keyed by type (unset a key to hide a type). The schemas are standalone, so
nothing is pruned. The CLI export commands and the static `public_path` files never run
serve decorators: the exported spec stays the full contract for diffing and codegen.

#### Objects in example and default values

So decorators don't rebuild the document on every request, the warmer stores it beside
the JSON as serialized PHP value objects, and a decorated request unserializes that copy.
The copy records the installed versions of core and the bundle, so one left behind by a
deploy reads as missing and the document is built again. Unserializing only allows
core's OpenAPI value objects, `stdClass` and enums. If an `example()`, a default or an
extension value holds another object (a `DateTimeImmutable` example, say), the warmer
can't store the copy: it logs a warning naming the class, and each decorated request
builds the document instead. List the class to cache it again:

```yaml
json_api:
  openapi:
    serve_allowed_classes:
      - DateTimeImmutable
```

Only list classes you trust to be unserialized from your own cache directory. Without
serve decorators the copy is never read, so the warning costs nothing.

#### Caching at the edge

Both `docs.json` and `schemas.json` always send an `ETag` and answer a matching
`If-None-Match` with a `304`. The comparison is weak, so the `W/` prefix a CDN adds when
it compresses the body still matches. How the ETag is computed depends on the
decorators:

| Serve decorators | ETag | Cost of a 304 |
|---|---|---|
| none | the warmed artifact's hash, stored beside it | one 32-byte file read |
| all implement `OpenApiServeCacheKeyInterface` | a hash of the artifact hash and every key, in decorator order | that read plus your `cacheKey()` calls |
| any without a key | a hash of the final body | a full decorate and serialise |

A partial key would let two different documents share an ETag, so one keyless decorator
switches that document kind to the body hash. The key mixes in each decorator's class
name, so adding or removing a decorator changes it; a change inside `decorate()` doesn't,
which is why the example carries a version.

Set `Cache-Control` for the document, schemas and viewer routes with `cache_control`:

```yaml
json_api:
  openapi:
    cache_control: 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400'
```

It is off by default, which leaves Symfony's `private` default in place. The docs routes
are often behind a firewall in production, and a `public` default would let a shared
cache hand an authenticated document to anyone. The responses carry no `Vary` header:
the routes don't negotiate (always JSON), and a CDN already keys on the host. If a
decorator reads anything other than the host, such as a cookie or an `Authorization`
header, keep `cache_control` private.

Nothing on the docs, schemas or viewer routes starts a session or sets a cookie, and a
lazy firewall leaves an anonymous request alone too. Under a stateful firewall,
anything that reads the security token (a locale listener, say) marks the session as
used, and Symfony's session listener would normally rewrite the response to `private`.
When `cache_control` is set, these responses opt out of that rewrite, so the configured
value is what the edge sees. A private document behind a login should therefore keep a
private `cache_control`.

## The `servers` list

Unless `json_api.openapi.servers` overrides it, each document's `servers` comes from
the JSON:API server it describes:

- **A configured `base_uri`** is the one server URL, as it is
  (`https://api.example.com/api/v5`).
- **An empty `base_uri`** advertises the prefix the server's routes are imported under,
  as a relative URL, so a client resolves `/articles` against the document's host as
  `/api/v5/articles`. Routes imported with no prefix advertise no `servers`, as before.
  A server imported under two prefixes gets one entry per prefix, sorted, and an
  unprefixed import beside them adds `/`.
- **A prefix with placeholders** (`/{tenant}/api`) becomes a templated URL with a server
  variable whose `default` is the route default for that placeholder:

```yaml
# config/routes/json_api.yaml
json_api_tenant:
  resource: default
  type: jsonapi
  prefix: /{tenant}/api
  defaults: { tenant: acme }
```

```json
"servers": [{ "url": "/{tenant}/api", "variables": { "tenant": { "default": "acme" } } }]
```

OpenAPI requires a variable default, so a placeholder prefix without a route default is
left out of `servers` rather than given an invented value.

The document is built from the compiled routes, not from a request, so it is the same
whether the cache warmer or the first request builds it. That also means it can't see
a front controller served below the web root (`/app/index.php`) or a proxy prefix: those
are only known per request. Set `json_api.openapi.servers` (or a configured `base_uri`)
when the API is reached through one.

## Multi-server

With several JSON:API [servers](multi-server-and-testing.md), the default is **one
document per server** (`/docs.json` for the default server, `/{server}/docs.json` for
each named server). Switch to a single union document with `multi_server: combined`:

```yaml
json_api:
  openapi:
    multi_server: combined   # one document spanning every server, at the json path only
```

## Conformance: proving the document describes reality

The headline guarantee is *round-trip*: the generated document is proven to describe the
responses you actually serve. The bundle ships a test trait, `SchemaConformanceTrait`,
that validates a **real** response body against the **generated** component schema for its
type — so a drift between your document and your API fails a test.

```php
use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenApiConformanceTest extends KernelTestCase
{
    use SchemaConformanceTrait;

    public function test_single_resource_matches_its_schema(): void
    {
        $response = /* your functional GET of /articles/1 → an HttpFoundation Response */;

        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Single);
    }

    public function test_collection_and_relationship_match(): void
    {
        $this->assertResponseMatchesGeneratedSchema($collection, 'articles', SchemaDocumentKind::Collection);
        $this->assertResponseMatchesGeneratedSchema($related, 'articles', SchemaDocumentKind::Related, 'author');
        $this->assertResponseMatchesGeneratedSchema($linkage, 'articles', SchemaDocumentKind::Relationship, 'author');
    }
}
```

`SchemaDocumentKind` selects which envelope component validates the response — `Single`
(`GET /{type}/{id}`), `Collection` (`GET /{type}`, incl. a compound `?include` document),
`Relationship` (`…/relationships/{rel}` linkage) or `Related` (`…/{rel}` for a to-one).
The trait builds the document once per server through the same `DocumentFactory` the
warmer / controller / CLI use, and validates over `opis/json-schema` — which implements
the 2020-12 dialect natively, so the helper is fully offline (no meta-schema to vendor).
`assertBodyMatchesGeneratedComponent($body, '<Component>')` is the escape hatch for a
component the four kinds don't name (a custom enum component, a per-relation collection).
The trait also checks each `included` resource against its own type's `<Type>Resource`
component, since the envelope's `included` schema only checks `type` and `id`.

### Sparse fieldsets

The read schemas mark every attribute `required`, because a full response always carries
them. A response to `?fields[articles]=title` leaves the other members out, so tell the
trait which fieldsets the request asked for. Pass the browser that made the request in
place of the response, and the trait reads `fields` from its last request:

```php
$client->get('/articles?include=author&fields[articles]=title,author&fields[authors]=name');

$this->assertResponseMatchesGeneratedSchema($client, 'articles', SchemaDocumentKind::Collection);
```

With a bare `Response` or body string, pass the map yourself as `fieldsets:`, in the same
shape as the query (`['articles' => 'title,author']`, or a list of names per type).
`assertBodyMatchesGeneratedComponent()` and `bodyMatchesGeneratedComponent()` take the
same argument.

For each type with a fieldset, attributes and relationships outside it stop counting as
required, in primary data and in `included`. Members inside the fieldset are still
required, and a type without a fieldset still requires everything, so a member that goes
missing from a full response still fails.

This is a real, biting guarantee: when a response diverges from its schema, the assertion
names exactly which member diverged. The bundle's own suite runs these assertions across
both the in-memory and Doctrine providers, so the generated document is proven against
the wire output of both data layers.

> Beyond the response round-trip, every emitted document is also validated against the
> official **OAS 3.1 meta-schema** in the bundle's tests, so the document is well-formed
> OpenAPI as well as accurate.
