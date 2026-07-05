# How it compares: API Platform (JSON:API format)

[API Platform](https://api-platform.com/) is an excellent, mature API framework
in which JSON:API is one of several output formats — one resource model can serve
JSON-LD/Hydra, JSON:API, HAL, GraphQL and more. This bundle is narrower and
deeper: JSON:API 1.1 is not a serializer format bolted onto a general pipeline,
it *is* the pipeline — routing, query parsing, validation pointers, pagination,
content negotiation and the OpenAPI document all speak JSON:API 1.1 as their
primary contract. If you want one model serving many representations (or
GraphQL), API Platform is the right tool; if you are building a JSON:API and
want the full 1.1 surface — relationship endpoints, atomic operations, cursor
pagination, profiles — this bundle covers ground API Platform's JSON:API format
does not attempt.

*Comparison as of 5 July 2026, against `api-platform/core` v4.3.16. Found an
error? [Open an issue](https://github.com/haddowg/json-api-symfony/issues).*

!!! note "Scope"
    This page compares against **API Platform's JSON:API output format**
    specifically — the shape a client sees when it negotiates
    `application/vnd.api+json`. It is not a verdict on API Platform as a
    product, which is much larger than any one format (see
    [where API Platform shines](#where-api-platform-shines)).

## Spec compliance

The clearest philosophical difference. API Platform produces a solid JSON:API
*document* — `data`/`type`/`id`, attributes, relationships with linkage,
`included`, errors, pagination links — and 4.3 added an entity-id mode with
`links.self`. But the parts of the spec that live outside the document — the
`ext`/`profile` media-type parameters, the atomic operations extension,
relationship endpoints — are not part of its JSON:API format, and its docs claim
"JSON:API" generally rather than 1.1. This bundle treats the whole 1.1
specification as the contract, backed by a spec-compliance suite in the
framework-agnostic core.

| Feature | This bundle | API Platform (JSON:API format) |
| --- | --- | --- |
| JSON:API 1.1 | **Yes** — full 1.1 document structure and endpoint semantics, backed by a spec-compliance test suite in the core library. | **Partial** — solid document structure and pagination links; no relationship endpoints, no `ext`/`profile` handling, and no dedicated 1.1 compliance suite. |
| Content negotiation | **Yes** — strict `Content-Type`/`Accept` negotiation including `ext` and `profile` media-type parameters (`415`/`406` on violations), a profile registration system, and a bundled implementation of the published cursor-pagination profile. See [routing](routing.md) and [configuration](configuration.md). | **Partial** — strict per-operation `406`/`415` across all enabled formats, but no `ext`/`profile` media-type parameter validation and no profile system. |
| Atomic operations | **Yes** — the atomic operations extension as an opt-in per-server endpoint: all-or-nothing batches with `lid` references and lifecycle hooks per operation. See [atomic operations](atomic-operations.md). | **No** — the extension is not implemented; batching is out of scope for its JSON:API format. |
| Error objects | **Yes** — every failure renders spec-shaped error objects with JSON Pointer or query-parameter sources, including nested pointers such as `/data/attributes/address/city`, plus mapping for your own exceptions. See [errors](errors.md). | **Partial** — exceptions and violations render as JSON:API `errors` arrays with `source.pointer`, but pointers omit the leading slash, do not descend into nested attribute members, and there are no query-parameter sources. |

## Resource definition

API Platform derives fields from PHP property types via `symfony/property-info`
and the Symfony Serializer, with `#[ApiProperty]` metadata and Validator
constraints attached separately — a model that generalises across all its
formats. This bundle instead uses a declarative typed-field builder where the
type, constraints, storage mapping and visibility of every attribute live in one
place — which is also what lets validation pointers, hydration and the OpenAPI
schema all agree on structured attributes.

| Feature | This bundle | API Platform (JSON:API format) |
| --- | --- | --- |
| Typed field system | **Yes** — declarative typed attributes (`Str`, `Integer`, `DateTime`, `Email`, `ArrayList`, …) with per-type constraint helpers, storage mapping, visibility scoping and serialize/hydrate hooks on one builder surface. See [resources](resources.md). | **Partial** — fields derive from PHP property types with `#[ApiProperty]` metadata and separately-attached Validator constraints; no declarative typed-field builder. |
| Composite attributes | **Yes** — `Map`, `Obj` and `OneOf` composites plus the `Shape` JSON Schema constraint, all validated with precise nested source pointers. See [composite attributes](composite-attributes.md). | **Partial** — embedded non-resource objects serialize as nested attribute objects and cascaded validation works, but there is no composite construct and `422` pointers do not reach nested members. |
| Encoded ids | **Yes** — the wire id can differ from the storage key via an attachable `IdEncoderInterface`, honoured bidirectionally through serialization, filters and the data layer. See [resources](resources.md). | **Partial** — an alternate property (e.g. a UUID column) can be the identifier, but hashids-style obfuscation means custom providers and URI-variable transformers. |
| Many types, one model | **Yes** — multiple resource types can project the same entity (public vs admin) with independent fields, operations and URL segments. See [resources](resources.md). | **Yes** — `#[ApiResource]` is repeatable: one class can declare several resources with independent `shortName`, operations, serialization groups and security; a documented pattern. |
| Capability composition | **Yes** — a type is composed from standalone capabilities: a bare serializer/hydrator pair with no `Resource` class, per-operation allow-lists, and per-relation endpoint gating. See [capability composition](capability-composition.md). | **Partial** — per-operation composition is strong (each operation carries its own provider/processor/input/output/security), but a resource is always a metadata-decorated class and relation endpoints do not exist to gate. |

## Reading

Both handle the everyday read vocabulary well — sparse fieldsets, includes,
sorting and a rich filter system. The differences appear at the edges of the
spec and at scale: relationship (linkage) endpoints, cursor pagination, and
keeping compound documents bounded when relations are large.

| Feature | This bundle | API Platform (JSON:API format) |
| --- | --- | --- |
| Fieldsets & includes | **Yes** — `fields[TYPE]` and nested `include` paths with depth caps, per-relation include opt-outs, and per-relationship sort/filter on included collections. See [relationships](relationships.md). | **Partial** — sparse fieldsets and nested dot-path includes work (with allow-listing via the `SparseFieldset` parameter filter), but no depth caps, per-relation opt-outs, or sort/filter on included collections. |
| Sorting & filtering | **Yes** — multi-field `?sort` with per-field opt-in, and a declarative filter vocabulary (`Where`, `WhereIn`, `WhereNull`, `WhereHas`, dotted-path `WhereThrough`) with pre-validated values failing as `400`. See [doctrine](doctrine.md). | **Yes** — `?sort` maps onto the order filter with per-property opt-in, and `filter[…]` reaches a rich per-backend filter system (search, date, range, exists, nested paths) with parameter constraint validation. |
| Pagination strategies | **Yes** — four strategies (page-number, offset, server-fixed, cursor) with defaults, caps, count-free paging and opt-in totals. See [pagination](pagination.md). | **Partial** — page-number pagination with per-resource defaults, caps, opt-in totals and a count-free partial mode; no offset, fixed or cursor strategies in the JSON:API format, and the parameters are `page[page]`/`page[itemsPerPage]` rather than `page[number]`/`page[size]`. |
| Cursor pagination | **Yes** — true keyset windows (no `COUNT`, no `OFFSET` scans) on primary, related and relationship-linkage collections, advertising the published cursor-pagination profile. See [pagination](pagination.md). | **No** — `paginationViaCursor` metadata is consumed only by the Hydra normalizer; the JSON:API collection normalizer builds page-number links exclusively. |
| Related & linkage endpoints | **Yes** — every relation gets both a related endpoint and a `…/relationships/{rel}` linkage endpoint, including polymorphic relations. See [relationships](relationships.md). | **Partial** — related collections can be modelled as subresources with a custom `uriTemplate`, but relationship (linkage) endpoints do not exist; linkage appears only inline in documents. |
| Bounded compound documents | **Yes** — windowed includes and related collections execute as SQL window functions and push-down queries; compound documents never trigger full-collection hydration. See [doctrine](doctrine.md). | **Partial** — eager-loading extensions avoid classic N+1, but included to-many collections are not bounded: a large relation serializes in full into `included`. |

## Writing & validation

Both frameworks auto-expose CRUD through a provider/processor pipeline with
before/after seams and mature custom-operation support. The gap is again
spec surface: with no linkage endpoints there is no relationship mutation
vocabulary in API Platform's JSON:API format — relationships change only by
`PATCH`-ing the parent with full linkage replacement — and validation pointers
stop at the top level of an attribute.

| Feature | This bundle | API Platform (JSON:API format) |
| --- | --- | --- |
| Full CRUD | **Yes** — all five operations auto-exposed per type (`201` + `Location`, `200`, `204`) through the provider/persister SPI, with client-generated-id and `lid` support. See [getting started](getting-started.md). | **Yes** — `GetCollection`/`Get`/`Post`/`Patch`/`Put`/`Delete` through the provider/processor pipeline with correct status semantics; client-generated ids are an explicit opt-in, without `lid` handling. |
| Relationship mutation | **Yes** — `PATCH`/`POST`/`DELETE` on linkage endpoints map to Replace/Add/Remove, with per-relation prohibition flags rejecting as `403`. See [relationships](relationships.md). | **No** — no linkage endpoints means no add/remove vocabulary; relationships change by `PATCH`-ing the parent resource with full linkage replacement. |
| Validation | **Yes** — field constraints compile to Symfony Validator constraints, yielding `422`s with precise pointers into nested composites, an entity-level post-hydration pass, and an optional document-first structural linter. See [validation](validation.md). | **Partial** — Validator constraints yield `422`s as JSON:API error objects with attribute/relationship pointers, but pointers omit the leading slash and do not reach nested members; no structural document linter. |
| Lifecycle hooks | **Yes** — before/after seams around every operation via Symfony event subscribers and per-resource hook methods; before hooks abort, after hooks replace the result. See [lifecycle hooks](lifecycle-hooks.md). | **Yes** — every operation flows through decoratable state providers/processors plus kernel and Doctrine listeners; a different mechanism with equivalent interception power. |
| Custom actions | **Yes** — non-CRUD actions declared on the resource with custom input/output types, per-action authorization, lifecycle events and `asLink` exposure. See [actions](actions.md). | **Yes** — arbitrary custom operations with custom `uriTemplate`, input/output DTOs, dedicated providers/processors and per-operation security; a mature, heavily documented pattern. |
| Async writes | **Yes** — a persister can return `AcceptedForProcessing` to answer `202` with a pollable job resource and a `303 See Other` on completion; the documented recipe uses Messenger. See [async](async.md). | **Partial** — `messenger: true` dispatches the write to a bus (commonly answering `202`), but there is no built-in pollable job resource or `303` completion flow. |

## Data layer

API Platform's state provider/processor architecture is genuinely excellent —
it is the platform's core design, and it ships **four** production data layers
(Doctrine ORM, Doctrine MongoDB ODM, Eloquent, Elasticsearch) where this
bundle ships one (Doctrine ORM here, Eloquent in the
[sibling Laravel package](https://github.com/haddowg/json-api-laravel)). Where
this bundle differs is the shipped in-memory reference pair, used as a test
double and conformance witness so the same suites run over both layers.

| Feature | This bundle | API Platform (JSON:API format) |
| --- | --- | --- |
| Provider/persister SPI | **Yes** — reads and writes flow through `DataProvider`/`DataPersister` interfaces resolved by priority plus first-supports-match. See [data layer](data-layer.md). | **Yes** — `ProviderInterface`/`ProcessorInterface` resolved per operation, with decoration of the defaults as the documented extension pattern; a fully equivalent SPI. |
| Reference data layers | **Yes** — an auto-registered Doctrine ORM layer via `#[AsJsonApiResource(entity: …)]` covering reads, transactional writes, eager-loaded includes and encoded ids. See [doctrine](doctrine.md). | **Yes** — broader: Doctrine ORM, Doctrine MongoDB ODM, Eloquent and Elasticsearch, all with filters, pagination and eager loading. |
| In-memory provider | **Yes** — a reusable in-memory provider/persister pair serves as test double and conformance witness; the docs run the same suites over it and the database layer. See [custom data providers](custom-data-providers.md). | **No** — no shipped in-memory reference implementation; the test story leans on real databases via `ApiTestCase`. |
| Filter/sort adapters | **Yes** — filters and sorts are metadata handled by swappable adapter arms, with built-in DQL translation and a documented seam for custom handlers. See [doctrine](doctrine.md). | **Yes** — filters are metadata executed by swappable per-backend arms (ORM/ODM extensions, Eloquent filters, Elasticsearch DSL) with a documented custom-filter extension point. |

## OpenAPI & tooling

Credit where due: API Platform generates OpenAPI 3.1 from the same metadata
that serializes and validates, and its JSON:API schema factory **does** emit
JSON:API-shaped schemas — `data`/`attributes`/`relationships` envelopes and
collection wrappers — into the document for `application/vnd.api+json`, sharing
its linkage resolver with the runtime normalizer so schema and document cannot
drift. The honest differences are narrower: its document cannot describe
relationship or related endpoints (they do not exist), its test assertions are
format-generic rather than JSON:API-specific, and there is no cross-framework
contract — resources are defined per application, so a Symfony API and a
Laravel API are separate definitions producing separate documents.

| Feature | This bundle | API Platform (JSON:API format) |
| --- | --- | --- |
| OpenAPI 3.1 | **Yes** — generated from the exact definitions that serialize and validate, covering every CRUD, relationship, related and custom-action route, with Swagger UI/ReDoc and a customization seam. See [OpenAPI](openapi.md). | **Yes** — OpenAPI 3.1 from the serving metadata with Swagger UI and ReDoc bundled, including JSON:API-shaped schemas for `application/vnd.api+json`; relationship/related endpoints are absent because those routes do not exist. |
| Cross-framework contract | **Yes** — the Symfony and Laravel integrations emit a byte-identical OpenAPI document, enforced by a byte-compat CI job; the same generated client works against either framework. | **No** — the Symfony and Laravel integrations share the generator, but resources are defined per app (entities vs models); there is no single definition set emitting one document across both. |
| Schema export & warmup | **Yes** — CLI export of the OpenAPI document and standalone per-type JSON Schema 2020-12 files, warmed for production via a cache warmer. See [OpenAPI](openapi.md). | **Yes** — `api:openapi:export` and `api:json-schema:generate` console commands plus metadata/route cache warmers. |
| Testing kit | **Yes** — document/error assertions, request and operation builders, and a `SchemaConformanceTrait` that proves the generated document against real responses over both providers. See [multi-server and testing](multi-server-and-testing.md). | **Yes** — `ApiTestCase` with `assertMatchesResourceItemJsonSchema` accepting a `jsonapi` format argument, plus the distribution and demo app; assertions are format-generic rather than JSON:API-specific. |

## Runtime & authorization

Both integrate idiomatically — attribute discovery, cache-safe routes, semantic
bundle configuration, security expressions and voters — and both are at home in
long-running runtimes (API Platform's distribution runs FrankenPHP in worker
mode by default). The one structural difference is multi-server support: this
bundle runs several JSON:API servers side by side, each with its own resources,
negotiation settings and OpenAPI document.

| Feature | This bundle | API Platform (JSON:API format) |
| --- | --- | --- |
| Idiomatic integration | **Yes** — zero-config discovery of `#[AsJsonApiResource]` services with auto-registered, cache-safe routes, semantic bundle config and a route loader. See [install](install.md). | **Yes** — zero-config discovery of `#[ApiResource]` classes with auto-generated cache-safe routes and full semantic bundle config. |
| Long-running runtimes | **Yes** — a documented long-lived-worker posture with scoped bindings, per-dispatch state clears and warmed artifacts. See [security & deployment](security.md). | **Yes** — the official distribution runs FrankenPHP in worker mode by default; Symfony Runtime enables Swoole/RoadRunner. |
| Multi-server / versioning | **Yes** — multiple servers (default plus admin, or versioned APIs) with per-server resources, config, routes, negotiation and OpenAPI documents. See [multi-server and testing](multi-server-and-testing.md). | **Partial** — one API, one config, one OpenAPI document per app; path-versioned resources can coexist via distinct `uriTemplate`s, and evolution is handled through `deprecationReason` plus `Sunset` headers. |
| Per-operation authorization | **Yes** — declarative `security:` expressions plus voters per operation, with denials rendered as JSON:API errors. See [authorization](authorization.md). | **Yes** — `security`/`securityPostDenormalize` expressions with voters per operation; denials render in the negotiated error format. |
| Granular authorization | **Yes** — per-relation security, per-object checks against the loaded model, and request-aware field predicates narrowing the visible/writable surface. See [authorization](authorization.md). | **Yes** — per-property `security` expressions narrow the field surface per caller, with object-level checks against the loaded entity; per-relation *endpoint* security does not arise (no relation endpoints). |

## Where API Platform shines

This page compares one format; API Platform is a platform. If any of the
following matter to you, it is the stronger choice — none of them is something
this bundle attempts:

- **Multi-format by design.** One resource model serves JSON-LD/Hydra
  (default), JSON:API, HAL, RFC 7807 Problem, plain JSON, CSV — and a full
  **GraphQL** server with queries, mutations and subscriptions.
- **A decade of production hardening and a huge ecosystem.** API Platform
  Admin (React-admin based), `create-client` SPA scaffolding, the distribution
  (Docker + FrankenPHP worker mode), Schema.org vocabulary mapping, and strong
  commercial backing. It is extremely actively maintained — v4.3.16 shipped the
  week this comparison was written.
- **Real-time and caching built in.** Mercure push integration, HTTP cache
  invalidation for Varnish/Souin, and Vulcain-friendly preload links.
- **Four production data layers in core.** Doctrine ORM, Doctrine MongoDB ODM,
  Eloquent and Elasticsearch — this bundle's story beyond Doctrine ORM and
  Eloquent is a custom provider you write yourself.
- **An MCP server module** (new in 4.3) exposing API operations as AI tools.
- **A mature deprecation workflow** with `Sunset` header support for evolving
  an API in place.

## Which should you choose?

Choose **API Platform** when:

- You need more than JSON:API — GraphQL, JSON-LD/Hydra, HAL, CSV, or several
  representations of one model at once.
- You want the ecosystem: an admin UI, generated SPA clients, Mercure
  real-time, HTTP cache invalidation, and years of community answers to
  whatever you hit next.
- Your data lives in MongoDB or Elasticsearch, where it ships production
  layers and this bundle would require a custom provider.
- You want a battle-tested dependency. This bundle is new and pre-1.0, with
  no install base or plugin ecosystem; API Platform has a decade-long head
  start in maturity and mindshare, and that gap is real.
- Your JSON:API needs stop at documents: well-formed resources, includes,
  sparse fieldsets, filters and page-number pagination — its JSON:API format
  covers that ground well.

Choose **this bundle** when:

- JSON:API 1.1 *is* your contract and you want the whole surface: relationship
  endpoints and mutation, atomic operations, `ext`/`profile` negotiation, and
  spec-shaped errors with precise pointers — including into nested composite
  attributes.
- You want cursor (keyset) pagination on every collection endpoint — primary,
  related and linkage — count-free and deep-page-safe, on the published
  profile.
- Structured JSON columns should be first-class API citizens: `Map`, `Obj`,
  `OneOf` and `Shape` composites with `422` pointers into nested members.
- One declarative resource definition should drive serialization, hydration,
  validation *and* the OpenAPI 3.1 document — with a shipped conformance trait
  proving the document against the responses actually served.
- You run (or may run) both Symfony and Laravel: the
  [sibling Laravel package](https://github.com/haddowg/json-api-laravel) emits
  a byte-identical OpenAPI document from the same definitions, so one generated
  client works against either framework.
- Compound documents must stay bounded at scale — windowed includes and
  related collections via SQL window functions and push-down queries.

Also worth knowing before you decide: this bundle is JSON:API only (no
GraphQL or other formats), has no admin UI or client-generator ecosystem, and
its Doctrine provider requires a custom provider for polymorphic to-many
relations (the docs ship a worked example). If those trade-offs fit, the rest
of these docs start at [getting started](getting-started.md).
