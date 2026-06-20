# API Platform vs `haddowg/json-api` (+ Symfony bundle) — feature-gap analysis

A prioritised, deduplicated survey of what **API Platform** (the Symfony-native,
idiomatic API framework) offers that our framework/storage-agnostic core
(`haddowg/json-api`) and/or its Symfony bundle + Doctrine reference adapter
(`haddowg/json-api-symfony`) do not, scored by relevance, value, effort, and the
layer (core / bundle / both) that owns each gap. Companion to
[`laravel-gap-analysis.md`](laravel-gap-analysis.md) — read alongside it; gaps that
recur are cross-referenced (`= Laravel #N`) so planning never double-counts.

## The lens (read this first)

API Platform is **far broader** than JSON:API and supports the JSON:API format only
to a **limited extent** — JSON:API is one negotiated output of a JSON-LD/Hydra-first
engine, and AP's own JSON:API schema/serialization path historically lags its JSON-LD
path (AP issues [#3930](https://github.com/api-platform/core/issues/3930),
[#7802](https://github.com/api-platform/core/issues/7802)). So this is **not** an
"AP does more, we're behind" survey. The single question throughout is:

> As someone who wants to **build and integrate a JSON:API in a Symfony app**, how
> does API Platform's **API surface** and **developer experience (DX)** compare to
> ours, and is there anything **obvious** AP caters for that we do not yet?

Every candidate gap is judged by its value **to a JSON:API author/integrator** — not
by AP's breadth as a general REST/GraphQL framework.

**Version surveyed:** API Platform **4.x** (4.0–4.3 docs; the metadata `#[ApiResource]`
attribute + state-provider/state-processor pipeline + the 4.1+ `QueryParameter`/`Parameter`
metadata model, with the legacy `#[ApiFilter]` deprecated for 5.0). Doc URLs are cited
per gap in the source surveys; key references collected in §6.

**Explicit non-goals** (out of scope; never logged as gaps — see §5): GraphQL;
Hydra / JSON-LD / the `@context` vocabulary; Mercure / real-time; the React admin /
create-react-admin; alternative persistence (ElasticSearch, MongoDB/ODM) except where
it illustrates a DX abstraction we lack; the AP distribution / Docker scaffolding; and
multi-format content negotiation (HAL/CSV/XML) — we are a JSON:API builder by definition.

**One given (already on our roadmap):** generated **OpenAPI documentation**
(OpenAPI/Swagger export + Swagger UI/ReDoc + JSON Schema). Greg wants this; it is
treated as an **accepted roadmap item** and characterised (what AP gives, what an
equivalent for us entails), not re-argued.

---

## 1. Executive summary — the obvious gaps a JSON:API builder would feel

**Twenty-six real gaps** survived dedup across the six dimensions. Most are low/medium
DX sugar or honest design tensions with the spec. The handful below are the ones a real
integrator coming from API Platform would *immediately* miss — these drive the roadmap.

### The headline: generated OpenAPI docs (accepted)

1. **Generated OpenAPI v3 documentation** — the single biggest DX gravity-well AP has
   and the one explicitly accepted onto our roadmap. From the same metadata that drives
   routing and (de)serialization AP derives a full OpenAPI v3 doc with **zero author
   effort**, serves it live (`/docs.json`), renders it as **Swagger UI + ReDoc**,
   generates **JSON Schema** per resource, **documents every query parameter**
   (filters/sort/include/fields/page), and exports via `bin/console api:openapi:export`.
   *We have nothing* — but we already hold all the raw material (type/field/relation
   inventory, the operation allow-list, declared filters/sorts/pagination, id policy,
   link conventions, the full validator constraint vocab; centralised in
   `TypeMetadataResolver`/`ResourceLocatorPass`). The work is the **metadata→OpenAPI
   projection** (core, reusable + framework-agnostic) + **serving/CLI** (bundle).
   *Value: high. Effort: L (spine) + M (UI/params). Layer: both.* The real cost is
   JSON:API↔OpenAPI modelling friction (the `{type,id,attributes,relationships,links}`
   envelope, compound `included[]`, the bracketed query params, error objects) — none
   of which fall out of a naive property walk. **AP's own JSON:API schema path is
   incomplete, so we can plausibly do JSON:API-OpenAPI *better* than AP** — a
   differentiator, not just parity. This subsumes gaps G1–G6 in the table.

### The next most compelling (rank order, by what the surveys substantiate)

2. **Declarative HTTP cache headers** (`cacheHeaders` → `Cache-Control`/`s-maxage`/`Vary`/
   `public`, per resource/operation). *Value: high. Effort: M. Layer: bundle.* The most
   obvious DX gap in the cross-cutting dimension and **entirely absent from the Laravel
   report** — a genuinely new finding. Today an author sets `Cache-Control` only
   imperatively inside an after-hook via `setResponse($resp->withHeader(...))`. A
   `cacheHeaders` arg on `#[AsJsonApiResource]` + a `json_api.defaults.cache_headers`
   key + a ViewListener pass is a bundle-only MVP, pure RFC-7234, no spec interaction.

3. **Declarative built-in filter *library* (property→strategy)** — `SearchFilter`/
   `DateFilter`/`RangeFilter`/`NumericFilter`/`BooleanFilter`/comparison/free-text as
   drop-in classes, with `author.name` dot-notation traversing relations for free.
   *Value: high. Effort: L. Layer: both.* We have a rich value-filter *vocabulary*
   (`Where`/`WhereIn`/`WhereHas`/pivot/relation-scoped) but **no ready-made
   partial/LIKE/date-interval/range/comparison filters and no dot-notation
   join-traversal** — each non-trivial match is the author hand-combining `Where`
   filters. Pure DX (JSON:API leaves filter semantics to the server). The dot-notation
   traversal is the hard part (the L). The **single highest-leverage query-layer gap.**

4. **Cursor (keyset) pagination as a low-ceremony, documented option** — AP's
   `paginationViaCursor: [['field'=>'id','direction'=>'DESC']]` is count-free, deep-page
   efficient, derives the keyset wiring itself. *Value: high. Effort: M. Layer: both.*
   We already **have** the strategy (`CursorPaginator` + `CursorBasedPage` +
   `CursorPaginationProfile`, swappable as the server default) — the gap is the
   **Doctrine push-down** (build the keyset `WHERE`/`ORDER` from a declared field) +
   a declarative one-liner opt-in + docs. **= Laravel #29/#30** (cursor column/direction;
   opaque encoded cursors) — same build, AP framing.

5. **Server-defined contextual field profiles (serialization groups)** — `#[Groups]` +
   per-operation `normalizationContext` let the **server** choose which members an
   operation/audience exposes (list-vs-detail, admin-vs-user), *independent* of the
   client's sparse fieldset. *Value: high. Effort: M. Layer: both.* We have client-driven
   sparse fieldsets + a hand-written request-aware serializer escape hatch, but **no
   declarative server-side profile**. A `->groups('detail','admin')` field option + a
   per-operation/per-audience active-profile resolver closes it — and **pairs naturally
   with OpenAPI** (groups are what AP documents per-operation schemas from).

6. **Per-operation / dynamic / sequenced validation groups** — `validationContext:
   ['groups'=>[...]]` per operation, role-driven dynamic groups, `GroupSequence`.
   *Value: high. Effort: M. Layer: both.* Our bridge resolves only the binary
   create/update **Context** + per-field `When`/`Sequentially`. The sharpest
   write-validation gap: "required on create, optional on update, plus an admin-only
   stricter rule" isn't declaratively expressible. Distinct from the Laravel imperative
   escape hatch (#35) — this is **declarative** group selection.

7. ~~**PATCH merge-before-validate**~~ — **ALREADY SHIPPED (2026-06-14, this session;
   bundle ADR 0049 attributes + ADR 0050 pivot, = Laravel #15).** AP denormalizes the
   PATCH onto the loaded object so cross-field/conditional rules see merged
   `current+submitted` state — and our validator now does exactly this (merges the
   stored wire-form attributes + per-member pivot under the incoming partial before
   validating). **This gap is CLOSED; the survey agent read pre-#15 state.** Struck here
   and in the table (G12).

8. **Auto-documented / self-describing filter + parameter metadata** — every AP filter's
   `getDescription()` auto-populates OpenAPI. *Value: high. Effort: M. Layer: both.*
   This is the **query-layer half of the OpenAPI item** (G3) — called out because the
   queryable surface is the highest-signal part of a JSON:API doc and we can emit it
   *more* precisely than AP from our JSON:API-native filter/sort/page model. Folds into #1.

9. **First-class custom / non-CRUD actions** (e.g. `POST /albums/{id}/publish`) that
   **reuse the type's serializer/validator/links**. *Value: high. Effort: M. Layer:
   bundle.* **= Laravel #14.** Our `Operation` enum is the fixed five CRUD verbs; a
   business action today means a hand-rolled controller building a `DataResponse` by
   hand. The lifecycle-hooks seam is adjacent but is *around* CRUD ops, not a way to
   *mount* a new endpoint. An action-operation descriptor emitting a route whose handler
   builds a `DataResponse` for the ViewListener closes it.

Everything else (ETag/304, write-only attributes, deprecation headers, name conversion,
extensible exception mapping, strict unknown-param rejection, input/output DTO
decoupling, count-free page pagination) is medium-or-below — real but not the first
thing an AP refugee reaches for. They are in the table.

**Note on parity:** on the **write-extension seam** (state processors), the **DataProvider/
DataPersister SPI** (vs AP's state providers/processors), **row-level query scoping**
(`DoctrineExtensionInterface` vs AP's query extensions), **declarative authorization**,
**errors**, **multi-server versioning**, and the **JSON:API-specific test client**
(`JsonApiBrowser`), we **match or exceed** AP for the JSON:API use case — see §4.

---

## 2. Prioritised gap table (all real gaps)

Sorted roughly by value (high → low) then effort (S → L), with the OpenAPI cluster
(G1–G6) first as the accepted roadmap spine. "Layer" is who owns the work. "Rel." is
relevance to a JSON:API builder. Cross-dimension duplicates are merged.

| # | Dimension | Gap | What AP does | What we have | Rel. | Value | Effort | Layer | Laravel # |
|---|-----------|-----|--------------|--------------|:---:|:---:|:---:|:---:|:---:|
| **G1** | OpenAPI | **Generated OpenAPI v3 doc served live + export CLI** | Derives full OAS3 from metadata, zero effort; `/docs.json` + `bin/console api:openapi:export [--yaml]` | nothing (but all metadata exists) | High | High | L | both | — |
| **G2** | OpenAPI | **Bundled Swagger UI + ReDoc interactive docs UI** | Customised Swagger UI (`/docs`) + ReDoc (`/redocs`) fetching `openapi.json`; "try it out" | nothing | High | High | M | bundle | — |
| **G3** | OpenAPI / Query | **Query params (filter/sort/include/fields/page) documented as OAS parameters** | Filter `getDescription()` auto-expands into OAS `parameters` | nothing (filters/sorts/page caps + include safeguards all declared) | High | High | M | both | — |
| **G4** | OpenAPI | **Per-operation/property/response doc overrides + examples** | `openapi:` option, `#[ApiProperty(openapiContext)]`, `openapi:false` to hide-not-remove | nothing | Med | Med | M | both | — |
| **G5** | OpenAPI | **OpenAPI customisation via factory decorator + info/servers/security config** | Decorate `OpenApiFactoryInterface`; `api_platform.yaml` info/servers/oauth | nothing | Med | Med | S | bundle | — |
| **G6** | OpenAPI / Testing | **JSON Schema from metadata (export + schema-conformance test assertions)** | `api:json-schema:generate` + `assertMatchesResourceItemJsonSchema()` | inbound opis linter (reverse dir); `expectResource()` exact-match (different) | Med | Med | M | both | partial (#11/#11a are exact-match, not schema) |
| **G7** | HTTP caching | **Declarative HTTP cache headers** (`cacheHeaders`: max_age/s-maxage/vary/public) | Resource/operation attr → `Cache-Control`/`Vary` | nothing declarative; `withHeader()` in an after-hook only | High | High | M | bundle | — |
| **G8** | Query | **Built-in filter *library* (property→strategy) + dot-notation relation traversal** | `SearchFilter`/`Date`/`Range`/`Numeric`/`Boolean`/comparison/free-text; `author.name` traverses | filter *vocabulary* (`Where`/`WhereIn`/`WhereHas`/pivot) the author wires per type; no LIKE/range/date-interval; no dot-traversal | High | High | L | both | — |
| **G9** | Query | **Cursor (keyset) pagination — low-ceremony, documented** | `paginationViaCursor:[['field','dir']]`, count-free, derives wiring | strategy exists (`CursorPaginator`+profile, swappable); no Doctrine push-down, no one-liner, no docs | High | High | M | both | #29/#30 |
| **G10** | Serialization | **Server-defined contextual field profiles (groups)** | `#[Groups]` + per-op `normalizationContext`; server picks list/detail/admin set | client sparse fieldsets + hand-written request-aware serializer | High | High | M | both | — |
| **G11** | Writes | **Per-operation / dynamic / sequenced validation groups** | `validationContext` per op, role-driven, `GroupSequence` | binary create/update Context + per-field `When`/`Sequentially` | High | High | M | both | — (distinct from #35) |
| ~~G12~~ | Writes | ~~PATCH merge-before-validate~~ **SHIPPED 2026-06-14 (ADR 0049/0050, = Laravel #15)** | denormalizes onto loaded object; rules see merged state | **now: validator merges stored attrs + per-member pivot under the partial before validating** | — | — | — | bundle | #15 ✅ |
| **G13** | Resource decl. | **First-class custom / non-CRUD actions** reusing the type's serializer/validator/links | `new Post(uriTemplate, controller)`; `__invoke($resource): $resource` auto-serialized/validated/persisted | nothing first-class; hand-rolled controller + `DataResponse` | Med | High | M | bundle | #14 |
| **G14** | Caching | **ETag / Last-Modified + conditional requests (304)** | Validator headers → `If-None-Match`/`If-Modified-Since` → 304 | nothing | High | Med | M | bundle | — |
| **G15** | Cross-cutting | **Extensible exception→status mapping** (`exceptionToStatus` / `#[ErrorResource]`) | declarative per-resource/op map + domain error resources into OpenAPI | fixed 3-arm `ExceptionListener`; map via `JsonApiExceptionInterface` or a thrown `HttpException` | Med | Med | M | bundle | #38 |
| **G16** | Cross-cutting | **Declarative deprecation + Sunset/Deprecation headers** | `deprecationReason`/`sunset` → RFC 8594 headers + OpenAPI | nothing | Med | Med | S | bundle | — |
| **G17** | Writes | **Input/output DTO decoupling** (write-command object distinct from entity) | `input:`/`output:` DTO per op; auto DTO↔entity map | custom serializer/hydrator reshapes wire, but always writes onto the entity; no validate-command-then-map seam | High | Med | L | both | — |
| **G18** | Writes | **Asymmetric write-only attributes** (accept on write, never render) | `denormalizationContext` group + write-only property | read-only fields (the inverse) only; write-only needs a custom hydrator | Med | Med | S | core | — |
| **G19** | Serialization | **Member name conversion** (camelCase domain ↔ kebab/snake wire) as a config knob | `api_platform.name_converter` remaps every member globally | per-field `make('name')` literal; `storedAs()` renames the column only | Med | Med | S | both | — |
| **G20** | Query | **Strict rejection of unknown/undeclared query params** | `strictQueryParameterValidation:true` → 400 any undeclared param | 400 unknown `filter[key]`/sort only; misspelled `?pag[number]`/`?foo` silently ignored | Med | Med | S | bundle | — |
| **G21** | Query | **Count-free / partial pagination for the page-number strategy** | `paginationPartial:true` keeps page numbers, skips COUNT | only cursor is count-free; `PagePaginator` always computes the total | Med | Med | M | core | #28 |
| **G22** | Resource decl. | **Declaring a type from a plain DTO + per-op alternate representations** | `#[ApiResource]` on a DTO; `input/output`; multiple `#[ApiResource]` per class for v1/v2 groups | capability-composition already decouples type from storage; missing per-op input/output divergence + "one entity, two types via groups" sugar | Med | Med | M | both | — |
| **G23** | Resource decl. | **Arbitrary custom URI templates + multi-level nested routes** | `uriTemplate` + `uriVariables`/`Link`; parent auto-scopes | fixed conventional paths; related/relationship endpoints cover the idiomatic sub-resource case; no arbitrary path, no >1-hop nesting | Low | Med | M | both | — |
| **G24** | Serialization | **Global serialization-pipeline decoration seam** (custom-normalizer ergonomics) | tagged priority-ordered normalizer wraps default for many types at once | per-type serializers (N classes) or handler decoration (too coarse) | Med | Low | M | core | — |
| **G25** | Caching | **HTTP cache invalidation / Cache-Tags + reverse-proxy purge** | `http_cache.invalidation` → `Cache-Tags` + auto-purge Varnish/Souin/Cloudflare/Fastly | nothing | Med | Low | L | bundle | — |
| **G26** | Query | **Per-parameter security gating + value-transforming param providers** | `QueryParameter(security:)` silently drops a param; `provider:` transforms (IRI→entity) | resource-level authz + filter-value validation; no per-param gate/transform | Low | Low | M | both | — |
| **G27** | Resource decl. | **Per-operation route-name override + custom path-segment naming** | `name:` per op; `shortName` per resource | fixed `jsonapi.{server?}.{type}.{action}`; no per-op override | Low | Low | S | bundle | #70 |
| **G28** | Resource decl. | **Auto-derived resource type/shortName from class name** | `Book` → `books` unless `shortName:` | mandatory explicit static `$type` (doubles as registry key) | Low | Low | S | both | #68 |
| **G29** | Cross-cutting | **Processor-style "decorate the persister" single-seam DX** | inject `PersistProcessor`, wrap it; one obvious write seam | lifecycle hooks + persister override + handler decoration (finer-grained, but split across 3 seams) | Low | Low | S | bundle | #1 (shipped) |
| **G30** | Resource decl. | **Config-file (YAML/XML) resource declaration** | operations/metadata in YAML/XML | attribute/PHP-only (and our resource is a live PHP object — can't be static config) | Low | Low | L | both | — |

> Rows G29 (processor seam) and the include-depth / circular-reference framing (folded
> into the note below) are **DX-parity notes, not capability gaps** — kept in the table
> only so the comparison is honest.
>
> **Circular-reference / max-depth (Serialization dimension):** AP's `enable_max_depth`
> + `#[MaxDepth]` is **already closed on our side** — our `?include` depth cap + per-relation
> `cannotBeIncluded()` + allowed-paths whitelist (bundle ADR 0037, **= Laravel #9b**), and
> JSON:API compound docs de-dup by `(type,id)` so cycles terminate structurally. Not logged
> as a gap.

---

## 3. Merged / cross-referenced duplicates

To keep planning honest about overlap with the Laravel report and across AP dimensions:

- **OpenAPI cluster (G1–G6)** is one roadmap item with six facets; G3 (query-param docs)
  and G8's *self-describing filter* facet are the same introspection seam
  (`FilterDescriptor`/`ParameterDescriptor`) viewed from the query layer — build once.
- **G9 cursor pagination = Laravel #29/#30.** Same build (Doctrine keyset push-down +
  opaque cursor codec), AP framing.
- **G12 PATCH merge-before-validate = Laravel #15** (`withExisting`) — **SHIPPED this
  session (ADR 0049/0050); struck from the open backlog.**
- **G13 custom actions = Laravel #14.** Same feature.
- **G15 exception mapping = Laravel #38.** Same tagged `ExceptionMapperInterface`.
- **G21 count-free page pagination = Laravel #28** (`withSimplePagination`).
- **G27 route-name override = Laravel #70; G28 auto-derived type = Laravel #68** — both
  already characterised there as low-priority honest tradeoffs.
- **G29 processor decoration = Laravel #1** (lifecycle hooks, **shipped**) — a positioning/
  docs note, not a feature.
- **G6 schema-conformance test assertion** partially overlaps Laravel #11/#11a (those are
  *exact-match* assertions, already done); the *schema-generated* variant is new and rides
  the OpenAPI item.

**New findings not in the Laravel report** (AP-specific): G7 (cache headers), G14 (ETag/304),
G25 (cache invalidation), G16 (deprecation headers), G10 (server-side serialization groups),
G11 (validation groups), G17/G18 (DTO decoupling, write-only attrs), G19 (name conversion),
G20 (strict param rejection), G8 (built-in filter library + dot-notation). The **caching
cluster (G7/G14/G25) and the filter-library/groups DX are the standout net-new signals.**

---

## 4. We already match or exceed API Platform

Honest accounting of where, **for the JSON:API use case**, we are not behind — so these
are not re-litigated as gaps.

- **Write-extension seam.** AP's "decorate the `PersistProcessor`/`RemoveProcessor`" ≈
  our **DataPersister override** + **lifecycle hooks** (`BeforeSave`/`AfterSave`/
  per-operation before/after as events *or* resource methods) + handler decoration —
  **finer-grained** (per-operation, per-relationship) than AP's whole-process wrap, with
  a declarative authorization layer (ADR 0043) on top.
- **Storage-agnostic SPI.** Our **DataProvider/DataPersister** SPI (per-type first-match,
  Doctrine ref impl + in-memory witness) is the direct JSON:API-scoped twin of AP's
  state-provider/state-processor pipeline — a parallel design, not a gap.
- **Row-level query scoping.** `DoctrineExtensionInterface` (runs on fetchOne /
  fetchCollection / fetchRelatedCollection, COUNT + 404 respect the scope) ≈ AP's
  `QueryCollectionExtensionInterface` — same model and same split.
- **Capability-composed, storage-decoupled type model.** We **exceed** AP's headline
  "declare an API from a class not tied to an entity": our whole type model is
  capability-composed (standalone serializer/hydrator/relations/provider/persister,
  nothing coupled to `AbstractResource`), whereas AP bolts DTO support onto an
  entity-first metadata model. Only the narrow per-op input/output residue remains (G17/G22).
- **Request-aware serialization.** Our `computed()` + request-aware attribute *set* can
  depend on the request **per object** — which AP's static `#[Groups]` cannot without a
  custom normalizer/context builder. (The server-side *declarative profile* G10 is the
  one piece we'd add.)
- **Relationships & query semantics.** Relationship-existence (`WhereHas`/`WhereDoesntHave`
  → Doctrine `EXISTS`), relation-scoped + belongsToMany-pivot filters/sorts,
  polymorphic endpoints, the include safeguards (per-relation + allowed-paths + max depth),
  and the spec's related/relationship endpoints are **richer and more JSON:API-faithful**
  than AP's generic `uriVariables`/`IriFilter` machinery.
- **Errors.** Route-scoped `ExceptionListener` rendering spec-compliant JSON:API error
  documents (three arms, debug-gated meta, firewall 401/403 mapped) — AP's RFC-7807
  Problem+JSON is a *different* (non-JSON:API) error format. (Only the *extensible
  mapping* facet G15 is missing.)
- **Multi-server versioning.** Config-declared named servers (prefixes/hosts, per-type
  assignment, per-server self-links; ADR 0034) is a **stronger** surface-split/versioning
  primitive than AP has (AP has no URL/version routing primitive, only deprecation headers).
- **Testing.** `JsonApiBrowser` (status+content-type+body asserted as a unit,
  `assertFetchedOne/Many/InOrder/Exact`, `expectResource($entity)` model-derived exact
  match, `actingAs()`) **exceeds** AP's `assertJsonContains` for JSON:API shapes. (Only the
  *schema-conformance* assertion G6 is additive, and it rides OpenAPI.)
- **Content negotiation / id strategy.** Core's `negotiate()` (415/406/400) and our
  Id-field encoding + source/policy model already cover these idiomatically; AP's
  IRI-as-id and multi-format negotiation are JSON-LD/general-REST conventions, not gaps.

---

## 5. Non-goals (explicit scope boundaries)

One line each; never logged as gaps.

- **GraphQL** — AP auto-exposes every operation to GraphQL + GraphiQL; out of scope.
- **Hydra / JSON-LD / `@context`** — AP's primary, most-complete representation and error
  format; we are JSON:API-only and would not emit Hydra.
- **Mercure / real-time** — subscription push and its cache propagation; out of scope.
- **React admin / create-react-admin** — schema-driven admin UI; out of scope.
- **Alternative persistence** (ElasticSearch, MongoDB/ODM) — out of scope except where it
  illustrates a DX abstraction we lack (our SPI is storage-agnostic; a custom provider owns
  its own filter execution).
- **Multi-format content negotiation** (HAL/CSV/XML/raw JSON output_formats) — AP being a
  general API framework; we deliberately commit to `application/vnd.api+json` (noted once at
  low/low only for the occasional CSV-export wish).
- **AP distribution / Docker scaffolding, `--api-gateway` export, `x-apiplatform-tags`
  per-audience spec variants** — deployment/infra concerns, not JSON:API authoring needs.
- **IRI-based linkage / `use_iri_as_id` / `IriFilter` / IriConverter param providers** —
  JSON:API uses `{type,id}` linkage + `links`, which we render natively.
- **AP's generic `PlaceholderAction`/controller plumbing** — exists only because AP routes
  through controllers; our router-native one-literal-path-per-operation model needs no equivalent.
- **Rate limiting** — AP has none AP-specific; it defers to Symfony's rate-limiter, exactly
  as we do. Not a gap on either side.
- **Bulk/batch writes & JSON Patch (RFC 6902)** — AP offers no first-class batch processor;
  JSON:API handles both via its own extensions. Not a gap on either side.

---

## 6. Suggested roadmap order

Sequenced cheap-wins-first within value tiers; **OpenAPI is already accepted** and anchors
the plan because so much else (G3/G8 query-param docs, G6 schema assertions, G10 groups,
G16 deprecation rendering) hangs off it.

**Tier 0 — cheap, high-signal, independent (land first):**
- **G7 declarative cache headers** (M, bundle) — biggest net-new DX win, no spec interaction.
- **G16 deprecation + Sunset headers** (S, bundle) — pairs with multi-server versioning + OpenAPI.
- **G18 write-only attributes** (S, core) — small, clear value for credentials/tokens.
- **G20 strict unknown-param rejection** (S, bundle) — opt-in client-typo guard.
- **G19 member name conversion** (S–M, both) — high-DX once sparse-fieldset/include path
  parsing is handled.

**Tier 1 — the OpenAPI spine (accepted; build as one cohesive slice):**
- **G1 → G3 → G2** then **G4/G5/G6** — core projection first (with the JSON:API envelope
  modelled correctly + the `FilterDescriptor` introspection seam that also serves G8/G3),
  then serving + Swagger/ReDoc, then overrides/schema-assertions. **Differentiate** by
  doing JSON:API-OpenAPI better than AP's incomplete path.

**Tier 2 — the high-value query/write DX (after Tier 0, alongside/after OpenAPI):**
- **G8 built-in filter library + dot-notation** (L, both) — highest-leverage query DX;
  its filter-descriptor work feeds G3.
- **G9 cursor pagination push-down** (M, both) — finish the existing strategy (= Laravel #29/#30).
- **G10 server-side field profiles** (M, both) — pairs with OpenAPI per-operation schemas.
- **G11 validation groups** (M, both) — the remaining sharp write-validation gap
  (**G12 merge-before-validate already shipped this session, ADR 0049/0050**).
- **G13 custom actions** (M, bundle, = Laravel #14) — rides the existing `DataResponse`/ViewListener.

**Tier 3 — medium, do on demand:**
- **G14 ETag/304** (M, bundle) — sits beside G7, gate by the same per-resource opt-in.
- **G15 exception mapping** (M, bundle, = Laravel #38) — tagged `ExceptionMapperInterface`.
- **G17 input/output DTO seam** (L, both) — blunted by the spec; build when a real
  write-command need appears.
- **G21 count-free page pagination** (M, core, = Laravel #28), **G22 alt-representation sugar**
  (M, both, possibly a docs recipe).

**Tier 4 — low / note-only:** G23 (arbitrary URIs), G24 (normalizer seam), G25 (cache
invalidation/purge — scope to `Cache-Tags` header only, defer the purger SPI), G26
(per-param gating), G27/G28 (route names / type derivation — Laravel #70/#68), G29 (processor
seam — positioning note), G30 (YAML/XML decl — effectively a non-goal).

---

*Working document — mirrors `docs/laravel-gap-analysis.md`; untracked, not committed.*
