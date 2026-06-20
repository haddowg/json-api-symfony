# OpenAPI generation (G1–G6) — design spec

> Scratch design doc (untracked, like `g13-custom-actions-design.md` and
> `g21-count-free-pagination-design.md`). The committed record will be the ADRs
> (core 0070+, bundle 0077+) and the authoring guide `docs/openapi.md`.
> Status: **agreed with Greg 2026-06-19**, pending slice-by-slice build.

## 1. Goal

Generate a faithful, rich **OpenAPI 3.1.0** description of a JSON:API surface
directly from the metadata the bundle already holds — served live and exportable —
plus standalone JSON Schema export and a response-conformance test guarantee.
This is the headline differentiator: API Platform's own JSON:API↔OpenAPI path is
weak (it's JSON-LD/Hydra-first); we project **from first-class JSON:API metadata**,
so we can do JSON:API-OpenAPI better than AP.

Closes G1–G6 from the canonical backlog, extended to also document **custom
actions** (G13, shipped after the original G1–G6 framing).

## 2. Decision log (agreed)

| # | Decision | Choice |
|---|----------|--------|
| D1 | OpenAPI version | **3.1.0 only** (JSON Schema 2020-12 dialect → our constraint vocab maps 1:1, no lossy down-projection) |
| D2 | Layering | **Projection in core** (framework-agnostic), **serving/CLI/UI/config in the bundle** |
| D3 | v1 scope | **Full fidelity, built as a sequence of slices** — every operation (CRUD + relationship endpoints + custom actions), all query params, request bodies, responses, and the complete component set |
| D4 | Document model | **Typed VO model authored in core** — no third-party runtime dep (the clean builder `oooas` is OAS-3.0-only/abandoned; the only live 3.1 lib `DEVizzent/cebe-php-openapi` is reader-shaped, magic-property, fights PHPStan L9 and 2020-12 keyword fidelity). Validate emitted docs against the **official OAS 3.1 meta-schema** via the existing `opis/json-schema` dev-dep |
| D5 | Multi-server | **One document per server by default**, a **combined-document mode available via config** |
| D6 | UI delivery | **CDN-linked, app-overridable** Twig template; a **single config-driven UI route** rendering **Swagger UI _or_ ReDoc (not both)**; plus **CLI export to JSON/YAML** |
| D7 | Descriptions/examples (G4) | **Inline core authoring** — add `->description()`/`->example()` to core field/relation/filter builders — **plus a bundle decorator seam** for wholesale overrides |
| D8 | Security (G5) | App declares named **security schemes in bundle config** → `components.securitySchemes`; operations/actions carrying a `security` expression get a **configurable per-operation security requirement** (optional global default); per-op override. We never infer scheme semantics from the authz expression |
| D9 | HTTP exposure | Doc JSON + UI routes **auto-exposed when `kernel.debug`**, **explicit config opt-in to expose in prod**. CLI export always available |
| D10 | Query params (G3) | **Enumerate concrete params** per operation — each `filter[<key>]`, `fields[<type>]`, `sort` (enum of sortable keys ±desc), `include` (known includable paths), paginator-specific `page[...]` — value schemas from the declared constraints |
| D11 | JSON Schema (G6) | **Both**: standalone per-type JSON Schema export **and** a reusable response-conformance TestCase helper (round-trip validation of real responses against the generated component schemas) |
| D12 | Error responses | **Enumerate applicable standard statuses** per operation (400/403/404/406/409/415/422/500 as relevant), all referencing one shared error-document component |
| D13 | Naming | Routes `/docs.json` (+ `/{server}/docs.json`), single UI route `/docs`; CLI `json-api:openapi:export` + `json-api:json-schema:export`; config root `json_api.openapi.*` (all paths configurable) |
| D14 | Delivery | Spec + ADR plan now (this doc); then **slice-by-slice workflow builds orchestrated and verified by the main thread**, autonomous between slices, stopping only on a real blocker / spec divergence / a decision needing Greg |
| D15 | Tagging | **Auto-tag each resource** (its operations grouped under one implicit tag, default name = humanized title-case of the type); refs declared **resource-level** (`tags: [...]` → all that resource's ops) + **per-action**; per-individual-CRUD-op tagging via the decorator. Top-level tag **definitions** (name/description/externalDocs/ordering) are **config-authoritative** with **auto-synthesis** of any referenced-but-undefined tag |
| D16 | Backed enums | `->enum(Class)` + `->in()` accept backed-enum cases (normalized to backing scalars; field `type` follows backing type); `In` retains the enum **class-string** → a **named reusable component** `#/components/schemas/<Enum>` ($ref'd, deduped). Per-value descriptions via a core **`#[EnumCaseDescription]` attribute + `DescribedEnum` interface + `DescribesEnumCases` trait**. Emit **both** a markdown `value→description` table in the schema `description` (the only form the free CDN renderers show) **and** `x-enum-varnames` + `x-enum-descriptions` (codegen-portable); config `enum_value_descriptions: both\|extensions\|description` (default `both`) |
| D17 | Generation timing | **Never per-request.** A **`CacheWarmer`** builds the per-server doc (+ per-type JSON Schemas) at `cache:warmup` (every prod deploy) into `%kernel.cache_dir%`; the controller serves the **pre-built artifact** (O(file read)) — lazy build-and-cache fallback in **dev** (`kernel.debug`, where resources change between edits). Optional **`public_path`** also emits a fully static `.json`/`.yaml` for the web server/CDN to serve with zero PHP. **Not a compiler pass** — resources are DI services, not instantiable at compile time. CLI export stays for explicit publishing / CI spec-diffing |

## 3. Architecture

```
core (haddowg/json-api) — framework-agnostic
├── OpenApi VO model            typed, immutable, ->toArray() → OAS3.1 JSON/YAML
│   ├── OpenApi, Info, Server, Paths, PathItem, Operation
│   ├── Parameter, RequestBody, Response, MediaType, Components
│   └── SecurityScheme, SecurityRequirement, Tag, ExternalDocs
├── JsonSchema VO model         typed JSON Schema 2020-12 node
├── SchemaProjector             type metadata → JSON Schema (attributes/resource object)
├── OpenApiProjector            full metadata → OpenApi document
└── Metadata\*Interface         the input CONTRACT the projector consumes
                                (types, fields, relations, filters, sorts,
                                 operations, id pattern, paginator kind,
                                 actions, server info)

bundle (haddowg/json-api-symfony) — Symfony integration
├── OpenApi\MetadataSource      implements core's contract from the compiled
│                               registry + booted resources (ServerProvider,
│                               TypeMetadataResolver, ResourceLocator,
│                               IdEncoderResolver, ActionRegistry, route descriptors)
├── OpenApi\DocumentFactory     build per server, apply decorators (pure projection)
├── OpenApi\DocumentWarmer      CacheWarmerInterface — pre-build per-server artifacts
│                               at cache:warmup → %kernel.cache_dir% (+ optional public_path)
├── Controller/route            GET /docs.json, /{server}/docs.json, GET /docs (UI);
│                               serves the pre-built artifact, lazy-builds in dev
├── Command                     json-api:openapi:export, json-api:json-schema:export
├── DI/config                   json_api.openapi.* (enabled, expose_in_prod, info,
│                               servers, security, ui{renderer,path,cdn}, multi_server)
├── OpenApiFactoryInterface     decorator seam (G4/G5 wholesale customisation)
└── Test\SchemaConformanceTrait round-trip response-validation helper (G6)
```

**The central seam = the metadata contract (D2).** Core owns the JSON:API→OAS
*semantics* but most of the *data* (operation allow-list, route/URI segments,
id route patterns, multi-server assignment, custom actions) is bundle-side. So
core defines a small set of read interfaces describing "a server's worth of
JSON:API metadata"; the bundle implements them from its compiled container +
booted resources. Core projects purely against the contract and is fully testable
with in-core fixtures (no Symfony).

## 4. The projection (the heart)

### 4.1 Field type → JSON Schema base (2020-12)

| Core field | JSON Schema |
|-----------|-------------|
| `Str` | `{type: string}` |
| `Integer` | `{type: integer}` |
| `Decimal` | `{type: number}` *(core serializes Decimal as a PHP float → JSON number; amended from the early string/`format: decimal` sketch — Slice 1, core ADR 0070)* |
| `Boolean` | `{type: boolean}` |
| `Date` | `{type: string, format: date}` |
| `DateTime` | `{type: string, format: date-time}` |
| `Time` | `{type: string, format: time}` |
| `Email` | `{type: string, format: email}` |
| `Url` | `{type: string, format: uri}` |
| `Slug` | `{type: string, pattern: <slug>}` |
| `Ip` | `{type: string, format: ipv4\|ipv6}` |
| `Uuid` | `{type: string, format: uuid}` |
| `Ulid` | `{type: string, pattern: <ulid>}` |
| `ArrayList` | `{type: array, items: …}` |
| `ArrayHash` | `{type: object, additionalProperties: …}` |
| `Map` | `{type: object, properties: {<child fields → schema>}}` (nested constraint cascade) |
| `Accessor` (computed) | author-declared via `->example()`/description; no `type` unless given |

### 4.2 Constraint → JSON Schema keyword

Near 1:1 — the whole reason 3.1 is the right target:

| Constraint | Keyword |
|-----------|---------|
| `Min`/`Max` | `minimum`/`maximum` |
| `ExclusiveMin`/`ExclusiveMax` | `exclusiveMinimum`/`exclusiveMaximum` |
| `MultipleOf` | `multipleOf` |
| `MinLength`/`MaxLength` | `minLength`/`maxLength` |
| `Pattern` | `pattern` |
| `MinItems`/`MaxItems` | `minItems`/`maxItems` |
| `UniqueItems` | `uniqueItems` |
| `MinProperties`/`MaxProperties` | `minProperties`/`maxProperties` |
| `In` | `enum` (+ named component & `x-enum-*` when backed-enum-sourced — §4.8) |
| `NotIn` | `not: {enum: […]}` |
| `EmailFormat`/`UrlFormat`/`UuidFormat`/`IpFormat` | `format: …` |
| `SlugFormat`/`UlidFormat` | `pattern` |
| `Required` | parent `required: [...]` (object level) |
| `Nullable` | `type: [<t>, "null"]` |
| `Each` | `items` |
| `AtLeastOneOf` | `anyOf` |
| `Sequentially` | merge (`allOf` if needed) |
| `When` | best-effort `if/then`; if condition isn't statically expressible → omit + note in description |
| `After`/`Before`/`Between` | **degraded to a `description` note** — both fixed *and* closure bounds. 2020-12 has no keyword that bounds a `date-time` **string** (`minimum`/`maximum` are numeric-only; `formatMinimum`/`formatMaximum` are non-standard and silently ignored). A fixed bound surfaces its literal ATOM value in the note; a closure bound is noted as dynamic. *(Amended from the early `minimum`/`maximum` sketch — Slice 1, core ADR 0070)* |
| `CompareField` | cross-field — not expressible per-property; surfaced in the property/op description |
| `RelationshipType` | linkage `type` const/enum |

Constraints that can't be expressed losslessly (`When` dynamic, `CompareField`,
**all** `After`/`Before`/`Between` date bounds — fixed and closure) **degrade to a
human-readable note** in the property `description` rather than emitting a wrong
schema.

### 4.3 Components emitted (per server)

- **Per type**: `<Type>Attributes` (4.1/4.2), `<Type>Resource` (resource object:
  `type` const, `id`, `attributes`, `relationships`, `links`, `meta`),
  `<Type>ResourceIdentifier` (linkage), create/update request schemas (different
  `required`/`readOnly` sets — write-only fields appear only in writes, read-only
  only in reads).
- **Per relationship**: relationship object schema (`links`, `data` linkage 1 or
  [], `meta`), polymorphic relations → `oneOf` of member identifiers. A **polymorphic
  to-many related endpoint** additionally emits a per-relation
  `<Base><Rel>RelatedCollection` document whose `data.items` is the `anyOf` of every
  member resource (a real response mixes member types, so reusing a single member's
  collection would drop the others); a monomorphic to-many reuses the related type's
  plain `<RelatedType>Collection`.
- **Document envelopes**: single-resource, resource-collection (with pagination
  `links` first/prev/next/last + `meta`), relationship document, **compound
  document** (`included: array`), and the shared **error document**
  (`errors: [{status,code,title,detail,source{pointer,parameter},meta}]`).
- `jsonapi` object, top-level `links`/`meta`.

### 4.4 Paths, parameters, responses

- One `PathItem` per route the route loader would emit, honouring the **per-type
  operation allow-list** and **per-relation endpoint exposure** (only declared
  endpoints appear). URI segment from `uriType`.
- **Parameters (D10)** enumerated from declared metadata:
  - `filter[<key>]` — one per declared filter, value schema from its constraints,
    description (+ singular/default noted).
  - `sort` — single param, `enum` of sortable keys with `-` variants.
  - `include` — single param, allowed includable paths (respecting include
    safeguards: allow-list, max depth, `cannotBeIncluded`). On a **related endpoint**
    (`GET /{type}/{id}/{rel}`) the paths are scoped to the **related** type, not the
    parent (the related resource is the primary data) — sourced from the relation's
    `relatedIncludablePaths()`.
  - `fields[<type>]` — one per type reachable in the document: the primary type plus
    the terminal type of every includable path (resolved by walking the relation
    graph). Only types whose field inventory the document describes contribute a
    parameter. On a related endpoint the primary type is the related type.
  - `page[...]` — paginator-specific (`number`/`size`, `offset`/`limit`, or
    `cursor`/`size`) resolved from the type's paginator; `withCount` advertised
    where `countable()`.
- **Request bodies**: create vs update schemas; relationship-mutation bodies
  (replace/add/remove) honour `cannotReplace`/`cannotRemove`/`cannotAdd`.
- **Responses (D12)**: per-operation enumerate applicable statuses (e.g. `Create`
  → 201 + Location, 400, 403, 404, 409, 415, 422, 500), all 4xx/5xx → shared error
  document. `204` for `Delete`/empty bodies.
- All JSON:API bodies use media type `application/vnd.api+json`.

### 4.5 Custom actions

Each `#[AsJsonApiAction]` → a `PathItem` under the `-actions` segment:
- method(s) from the descriptor; resource vs collection scope path.
- **input**: `None` → no `requestBody`; `Document` → the `inputType`'s request
  schema; `Raw` → `requestBody` with a generic/author media type + binary schema.
- **output**: the `outputType`'s document schema, or `204`.
- per-action `security` → security requirement (D8).

### 4.6 Security (D8)

`json_api.openapi.security.schemes` config → `components.securitySchemes`
(`http`/bearer, `apiKey`, `oauth2`, `openIdConnect`). An operation/action carrying
a `security` expression gets the configured requirement (default
`security.default_requirement`, overridable per type/op). Optional document-level
default. The expression itself is never parsed for scheme semantics.

### 4.7 Tagging (D15)

OAS tags drive how Swagger UI / ReDoc group operations; without them every
operation lands in one ungrouped bucket. Tagging is **OAS-only grouping with no
JSON:API meaning**, so the declaration lives in the **bundle** (attribute args +
config) and core only carries the `Tag` VO + `Operation.tags` field in the model
plus the tag data in the metadata contract.

**Tag references** (which group an operation belongs to):
- **Resource-level** — `#[AsJsonApiResource(tags: [...])]` (`list<string>`), applied
  to *every* operation of that resource (CRUD + relationship endpoints).
  **Default when unset** = `['<HumanizedType>']` — humanized, title-cased,
  pluralized from the type via Symfony's `EnglishInflector` (e.g. `blog-post` →
  `'Blog Posts'`); a heuristic, always overridable.
- **Per-action** — `#[AsJsonApiAction(tags: [...])]`; **default** = inherit the
  resource tag of the action's mount `type` (so actions group with their resource).
- **Standalone-registered types** (no `AbstractResource`) — `tags` arg on the
  standalone attribute (`#[AsJsonApiSerializer]` etc.) or config `type → tags`;
  default = humanized type.
- **Per-individual-CRUD-operation** tags (tag `GET /{type}` differently from
  `POST /{type}`) — via the `OpenApiFactoryInterface` decorator (§5); not native.

**Tag definitions** (the top-level `tags:` array — `name` + `description` +
`externalDocs` + ordering):
- **`json_api.openapi.tags: [{name, description, externalDocs}]` is authoritative**
  — controls descriptions, externalDocs, and emit order.
- Any tag **referenced but not defined** in config is **auto-synthesized**
  (name only; plus an inline description if a resource supplied one for its own tag).
- **Precedence**: config wins → inline description → name-only synthesis.
  **Order**: config order first, then discovery order.

**Projection**: the bundle `MetadataSource` resolves per-operation tag refs
(resource/action/standalone/config + defaults) and the definition set (config +
synthesis); the core `OpenApiProjector` emits `Operation.tags` and the document-root
`tags:` from the contract.

### 4.8 Backed enums (D16)

`In → enum` (§4.2) is the mapping; backed enums are the idiomatic source of an
enumerated attribute, so they're first-class.

- **Builder**: `->enum(Status::class)` on `AbstractField` (and `->in()` also accepts
  backed-enum *cases*). Both **normalize to the backing scalar values**, so `In`
  stays `list<scalar>` and the validator/in-memory/filter consumers are unchanged.
  The field `type` follows the enum's backing type (string|integer). **Backed enums
  only** — a pure `UnitEnum` has no wire value.
- **Class identity retained**: `In` additionally keeps the enum **class-string**
  (optional) so the projector emits a **reusable named component**
  `#/components/schemas/<Enum>` and `$ref`s it from every usage (deduped), instead of
  repeating an inline `enum`.

**Per-value descriptions** — core ships an opt-in trio (generic PHP, no Symfony;
`$case->name` already gives varnames for free, so only descriptions need declaring):

```php
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)]
final readonly class EnumCaseDescription { public function __construct(public string $description) {} }

interface DescribedEnum { public function description(): ?string; }        // opt-in marker + contract
trait DescribesEnumCases { /* reflection impl: description() + static descriptions(): array<value,desc> */ }
```
```php
enum Status: string implements DescribedEnum {
    use DescribesEnumCases;
    #[EnumCaseDescription('Not yet visible to readers')] case Draft = 'draft';
    #[EnumCaseDescription('Live and public')]            case Published = 'published';
}
```

**Emission** (the convention reality — verified 2026-06-19): the free CDN renderers
do **not** show enum vendor extensions — native **Swagger UI** ignores them (plugin
required) and **ReDoc CE** renders `x-enumDescriptions` only in Redocly's paid
Reference, not Community. The **only** universally-rendered surface is the schema
`description`. So emit **both**:
1. a markdown **`value → description` table appended to the schema `description`**
   (renders in Swagger UI + ReDoc CE — guaranteed visual), and
2. structured **`x-enum-varnames`** (case names, free for *any* enum) +
   **`x-enum-descriptions`** (parallel arrays aligned to `enum` — the
   openapi-generator/NSwag-portable form + Swagger UI plugin + codegen).

Configurable: `enum_value_descriptions: both | extensions | description` (default
`both`).

## 5. Customisation (D7)

- **Inline (core)**: `->description(string)` and `->example(mixed)` on
  `AbstractField`, `AbstractRelation`, and the filter builders; resource-level
  `description`/external-docs via `#[AsJsonApiResource]` or a method. Core ADR.
- **Wholesale (bundle)**: `OpenApiFactoryInterface` decorator(s) — receive the
  built `OpenApi` VO, return a mutated one (compose multiple by priority). The
  app's last word over anything the projector produced.
- **Config (G5)**: `info` (title/description/version/contact/license/tos),
  `servers` (override/augment the per-server base URI list), `externalDocs`,
  `tags`.

## 6. Config sketch

```yaml
json_api:
  openapi:
    enabled: true            # generation available (CLI always works)
    expose_in_prod: false    # HTTP routes outside kernel.debug (D9)
    multi_server: per_server # per_server (default) | combined (D5)
    enum_value_descriptions: both  # both | extensions | description (D16)
    json:
      path: /docs.json
    public_path: ~           # also emit a static .json/.yaml here at cache:warmup (D17);
                             # null = controller-only (served from the cache dir)
    ui:
      enabled: true
      renderer: swagger      # swagger | redoc (D6 — one, not both)
      path: /docs
      cdn: ~                 # override the pinned CDN URL; null = bundle default
    info:
      title: 'My API'
      version: '1.0.0'
      description: ~
      contact: { name: ~, email: ~, url: ~ }
      license: { name: ~, url: ~ }
    servers: ~               # null = derive from each server's base URI
    security:
      schemes:
        bearer: { type: http, scheme: bearer, bearerFormat: JWT }
      default_requirement: [bearer]   # applied to ops carrying a security expr
    externalDocs: ~
    tags:                    # top-level tag DEFINITIONS, authoritative (D15)
      - name: Articles
        description: 'Blog articles and posts'
        externalDocs: { url: 'https://…', description: ~ }
```

## 7. CLI (D6, D11, D13)

- `json-api:openapi:export [--server=default] [--format=json|yaml] [--output=FILE]`
  — write the OAS doc (stdout if no `--output`).
- `json-api:json-schema:export [--server=default] [--type=…] [--output=DIR|FILE]`
  — standalone per-type JSON Schema 2020-12 (resource object + attributes).

Sets the `json-api:` command-namespace convention (the bundle has no commands yet).

## 8. Conformance guarantee (D11, G6)

A reusable test trait/`TestCase` helper:
`assertResponseMatchesGeneratedSchema($response, $type, $documentKind)` — validates
a real API response against the **generated** component schema via `opis/json-schema`.
Run across the dual-provider conformance kernels so the *generated* doc is proven to
describe the *actual* responses. This is the round-trip correctness anchor.

Plus: every emitted document is validated against the **official OAS 3.1
meta-schema** in core + bundle tests (D4).

## 9. Slice plan (D14 — sequential workflow builds, thread-orchestrated)

Each slice: its own ADR(s), green gates (PHPStan L9 + PER-CS + dual-provider
suites), self-contained, builds on the prior. The thread runs a tailored workflow
per slice, verifies the gates itself, then proceeds autonomously.

- **Slice 1 — core: JSON Schema projection + inline authoring + backed enums.**
  Typed JSON Schema VO + `SchemaProjector` (4.1/4.2, Map cascade);
  `->description()`/`->example()` on core builders. **Backed-enum support (4.8)**:
  `->enum()`/`->in()` enum-awareness + `In` class-string retention + the
  `EnumCaseDescription` attribute / `DescribedEnum` interface / `DescribesEnumCases`
  trait; projector emits the **inline** enum schema (enum, type, `x-enum-varnames`,
  `x-enum-descriptions`, markdown-in-`description`). Per-type attributes +
  resource-object schema. Meta-validate against JSON Schema 2020-12.
  *Core ADR 0070 (projection) + 0071 (field/enum builder vocab additions).*

**Layering (D2): all metadata→OAS *projection* is core (Slices 2–3); the bundle
(Slices 4–5) *implements the contract* + serves.** Route/operation/server/tag/action/
security data is bundle-side and passed into the core projector via the contract.
(Re-sliced 2026-06-19 from the original 6 — the old "bundle path projection" slice
violated D2; path projection is core.)

- **Slice 2 — core: OAS 3.1 VO model + metadata contract + component projection.**
  Typed OAS 3.1 VO model (OpenApi / Info / Server / Components / PathItem / Operation /
  Parameter / RequestBody / Response / MediaType / Header / SecurityScheme /
  SecurityRequirement / `Tag` / ExternalDocs, reusing the Slice-1 `Schema`;
  `Operation.tags`), each with `toArray()`/`toJson()`. The framework-agnostic
  `Metadata\*Interface` **contract** the projector consumes (the bundle implements it
  in Slice 4 — incl. tag refs + definitions, action + security data). The
  `OpenApiProjector` assembles the document **skeleton** (openapi / info / servers /
  jsonapi / tags / securitySchemes) + **components + document envelopes** (4.3:
  resource object, identifier/linkage, relationship object, error document,
  single/collection/relationship/compound envelopes) + **named reusable enum
  components** (`$ref`, deduped — 4.8). Meta-validate the (path-less) document against
  the OAS 3.1 schema over in-core contract fixtures.
  *Core ADR 0072 (VO model), 0073 (contract + component projection).*

- **Slice 3 — core: path / operation projection (G3 + actions + security + tags).**
  Extend `OpenApiProjector` to build **paths** from the contract — each type's allowed
  CRUD operations + relationship endpoints + custom-action paths → `PathItem`s with
  enumerated parameters (4.4: `filter[]`/`fields[]`/`sort`/`include`/`page[]`), request
  bodies (create/update + relationship-mutation + action input modes 4.5), enumerated
  standard error responses (D12), per-operation **tags** (4.7) and **security
  requirements** (4.6/D8), honouring the operation allow-list + per-relation endpoint
  exposure. The full document now validates against OAS 3.1.
  *Core ADR 0074 (path/operation projection).*

- **Slice 4 — bundle: implement contract + serve + warm + export + config.**
  `MetadataSource` implementing the core contract from the compiled registry + booted
  resources (ServerProvider / TypeMetadataResolver / ResourceLocator / IdEncoderResolver
  / ActionRegistry / route descriptors) — incl. **tag** ref/definition resolution +
  humanized-title-case default, **action** descriptors, and **security** schemes/
  requirements from config; `DocumentFactory` (per-server, decorator hook);
  **`DocumentWarmer` (CacheWarmerInterface)** → `%kernel.cache_dir%` (+ optional
  `public_path`), controller serving `/docs.json` + `/{server}/docs.json` with a dev
  lazy-build fallback (D17); `json-api:openapi:export` + `json-api:json-schema:export`;
  `json_api.openapi.*` config (enabled / expose_in_prod / multi_server / info / servers /
  security / tags / public_path). Functional: boot kernel → hit endpoint → meta-validate;
  warmer dumps the artifact.
  *Bundle ADR 0077 (serving + warmer + config), 0078 (CLI).*

- **Slice 5 — bundle: UI + decorator + conformance + docs.**
  Single config-driven UI route (Swagger|ReDoc, CDN, overridable Twig);
  `OpenApiFactoryInterface` decorator seam; the `assertResponseMatchesGeneratedSchema`
  conformance helper + dual-provider round-trip tests (G6/D11); `docs/openapi.md`;
  example-app wiring (resource/action tags, enum descriptions, security config).
  *Bundle ADR 0079 (UI), 0080 (decorator + conformance).*

## 10. Acceptance

- Every emitted document validates against the **OAS 3.1 meta-schema**.
- Generated component schemas validate **real responses** across the **in-memory
  and Doctrine** conformance kernels (round-trip).
- `/docs.json` + the UI render for the example app; CLI exports JSON + YAML.
- Multi-server: per-server docs by default; combined mode via config.
- PHPStan L9 + PER-CS 2.0 green on both repos; spec-grouped suites green on both
  providers.

## 11. Risks / watch-items

- **Core builder API addition pre-1.0** (`description`/`example`) — cheap now,
  expensive post-freeze; flagged + ADR'd. Keep it minimal (two methods + storage).
- **`When`/`CompareField`/date-bound** constraints — *intentionally* lossy
  (note-in-description, never a wrong schema). All `After`/`Before`/`Between` bounds
  (fixed and closure) degrade, since 2020-12 has no keyword to bound a `date-time`
  string. Document the limitation.
- **Generation cost** — never per-request (D17): a `CacheWarmer` pre-builds
  per-server artifacts at deploy (`cache:warmup`) into the cache dir (+ optional
  static `public_path`); the controller serves the pre-built file; dev builds on
  demand. CLI builds fresh. The warmer is **optional** (a docs failure must not break
  deploy) with the controller lazy-build as the safety net.
- **CDN UI + CSP** — document the CSP allowance; `cdn: ~` override + self-host recipe
  for air-gapped apps (we chose CDN-default deliberately, D6).
- **Param explosion** for types with many filters (D10) — accepted; it's the
  precision that beats AP. No silent cap.
</content>
</invoke>
