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
```

The page loads Swagger UI / ReDoc from a pinned public CDN (jsDelivr) and points it at
the document route, generated via the router (so the spec URL honours any routing prefix
you mount the imported routes under — `->prefix('/api')` — as well as the front-controller
script base; the viewer and the document it points at always share the same mount). For an
**air-gapped** deployment, mirror the assets
and set `ui.cdn` to your origin; document a matching `script-src` / `style-src` CSP
allowance for whichever origin you use.

To replace the viewer wholesale, register your own controller on `ui.path` — because your
app imports the docs route loader, your own `GET /docs` route wins by registration order.

## Production: the cache warmer

The document is **never built per request**. A `CacheWarmer` pre-builds each server's
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

# Standalone per-type JSON Schema 2020-12 documents:
php bin/console json-api:json-schema:export --type=articles            # one type → stdout
php bin/console json-api:json-schema:export --output=build/schemas/    # every type → directory
```

The standalone JSON Schemas are projected by the **same** core projector the OpenAPI
document uses, so a standalone `<type>.json` and the in-document `<Type>Resource`
component agree.

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

An action with no `tags` inherits the resource tag of the type it mounts on. The
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

```php
#[AsJsonApiResource(securityRead: 'is_granted("ROLE_USER")')]
final class PlaylistResource extends AbstractResource { /* … */ }
```

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

This is a real, biting guarantee: when a response diverges from its schema, the assertion
names exactly which member diverged. The bundle's own suite runs these assertions across
both the in-memory and Doctrine providers, so the generated document is proven against
the wire output of both data layers.

> Beyond the response round-trip, every emitted document is also validated against the
> official **OAS 3.1 meta-schema** in the bundle's tests, so the document is well-formed
> OpenAPI as well as accurate.
