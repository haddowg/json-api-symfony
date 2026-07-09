# Compared: API Platform

This page compares [`haddowg/json-api`](https://github.com/haddowg/json-api) (the
framework-agnostic core) plus [`haddowg/json-api-symfony`](https://github.com/haddowg/json-api-symfony)
(this bundle) against [API Platform](https://api-platform.com)
([`api-platform/core`](https://github.com/api-platform/core)) — specifically API
Platform's JSON:API output format, and its broader resource/API tooling where it
overlaps with what a dedicated JSON:API library does.

**The comparison is honest in both directions.** API Platform is a mature,
widely-adopted, multi-format API framework with years of production hardening; on
several dimensions below that maturity is a real advantage and we say so plainly.
These libraries are dedicated, single-format JSON:API tools; on the JSON:API
surface itself — spec coverage, extensions, profiles, format-specific error
handling, format-faithful OpenAPI — the depth comparison consistently favours the
dedicated library, and each claim below links its evidence.

**Versions.** All API Platform claims are qualified against **v4.3.16** (the
current stable, released 2026-07-03). The old-stable 4.2.x line receives security
fixes only; 4.4.0-alpha.x and 5.0.0-alpha.1 are in active development on `main`
(5.0 will flip the `jsonapi.use_iri_as_id` default). Open issues cited below were
verified open, and closed ones verified closed, against the upstream tracker at
the time of writing.

**Scope.** "Ours" here means the core library plus this Symfony bundle. A sibling
Laravel package ([`haddowg/json-api-laravel`](https://github.com/haddowg/json-api-laravel))
exists and is actively developed, but its feature depth is not evaluated on this
page — the Laravel row below compares API Platform's Laravel bridge against the
*architecture*, not against that package.

---

## At a glance

| Dimension | Verdict |
| --- | --- |
| [Document structure, CRUD & full spec coverage](#document-structure-crud--full-spec-coverage) | Advantage: this library |
| [Content negotiation (media-type parameters, 406/415)](#content-negotiation-media-type-parameters-406415-handling) | Advantage: this library |
| [Extensions mechanism (`ext=`)](#extensions-mechanism-ext-media-type-parameter) | Advantage: this library |
| [JSON:API format maintenance & stability signal](#jsonapi-format-maintenance--stability-signal) | Advantage: this library |
| [`atomic:operations` extension](#atomicoperations-extension--parsing-execution--results) | Advantage: this library |
| [Profiles mechanism, registry & advertisement](#profiles-mechanism-registry--advertisement) | Advantage: this library |
| [Cursor-pagination profile](#cursor-pagination-profile-ethan-resnick-spec) | Advantage: this library |
| [Author-published/custom profiles](#author-publishedcustom-profiles-countable-relationship-queries) | Advantage: this library |
| [OpenAPI engine completeness & fidelity](#openapi-engine-completeness--fidelity-paths-schemas-parameters-from-metadata) | Advantage: this library |
| [OpenAPI response declarations & vendor extensions](#openapi-response-declarations--vendor-extension-fidelity-async-job-lifecycle-self-describing-pagination) | Advantage: this library |
| [Typed TypeScript client + query-cache integration](#typed-typescript-client--query-cache-integration) | Advantage: this library (via a young sibling project) |
| [Schema fidelity for third-party codegen](#schema-fidelity-enabling-third-party-codegen-for-compound-documents) | Advantage: this library |
| [Declared filter vocabulary & operators](#declared-filter-vocabulary--operators) | Advantage: this library |
| [Filter value validation, defaults, fixed values & singular collapse](#filter-value-validation-defaults-fixed-values--singular-collapse) | Advantage: this library |
| [Author-composed filter groups](#author-composed-filter-groups-andornot-combinators) | Advantage: this library |
| [Relationship-path traversal filters](#relationship-path-traversal-filters-dotted-path-exists-semi-join) | Different approaches |
| [Multi-column sorting with defaults](#declaredcomputed-multi-column-sorting-with-defaults) | Advantage: this library |
| [Pagination strategies offered](#pagination-strategies-offered-pageoffsetfixed-pagecursor) | Advantage: this library |
| [Client-selectable strategy menu (`page[kind]`)](#client-selectable-pagination-strategy-menu-pagekind) | Advantage: this library |
| [Cursor pagination on included/related collections](#cursor-pagination-on-includedrelated-collections) | Advantage: this library |
| [Sparse fieldsets](#sparse-fieldsets-fieldstype-output-narrowing) | Advantage: this library |
| [Compound documents / includes](#compound-documents--includes--safeguards--n1-safe-batching) | Advantage: this library |
| [Error catalogue & typed exception model](#error-catalogue--typed-exception-model) | Advantage: this library |
| [Stable machine-readable error codes](#stable-machine-readable-error-codes) | Advantage: this library |
| [Error message localization](#error-message-localization) | Advantage: this library |
| [Request/response document schema validation](#requestresponse-document-schema-validation) | Advantage: this library |
| [Per-resource JSON Schema publication](#per-resource-json-schema-publication-createupdate-fragments-export) | Parity |
| [Framework-agnostic core](#framework-agnostic-core-psr-71517-zero-mandatory-coupling) | Parity |
| [Native Symfony/Doctrine integration depth](#native-symfonydoctrine-integration-depth) | Different strengths |
| [Native Eloquent/Laravel integration depth](#native-eloquentlaravel-integration-depth) | Different approaches |
| [N+1 avoidance (linkage, counts, includes)](#n1-avoidance-for-relationship-linkage-counts--compound-includes) | Advantage: this library |
| [SQL push-down for windowed/paginated relations](#sql-push-down--windowing-for-paginated-included-or-nested-to-many-relations) | Advantage: this library |
| [Count-free pagination by default](#count-free-pagination-by-default) | Parity |
| [Streaming serialization for large responses](#streaming-serialization-for-large-collection-responses) | Different approaches |
| [JSON:API-specific test assertions & helpers](#jsonapi-format-specific-test-assertions--helpers) | Advantage: this library |
| [General test-client maturity](#general-test-client-maturity) | Advantage: API Platform |
| [Production track record, adoption & release cadence](#production-track-record-adoption--release-cadencegovernance) | Advantage: API Platform |
| [Multi-protocol output & MCP exposure](#multi-protocol-output-hydrajson-ld-graphql--mcp-agent-tool-exposure) | Different approaches |

---

## JSON:API 1.1 conformance (spec coverage, content negotiation, extensions)

### Document structure, CRUD & full spec coverage

The core library implements the full JSON:API 1.1 document model — the `jsonapi`
object, links, resource and resource-identifier objects, compound documents,
error documents — plus fetch/create/update/delete for resources *and*
relationships, client-generated ids, and `lid` local identifiers. Every
requirement is tracked in a published
[spec-compliance table](https://github.com/haddowg/json-api/blob/main/docs/spec-compliance.md)
whose 60 rows each cite the test proving them; none is marked partial or missing.
The bundle exposes the full endpoint set — collection, single-resource, related,
and relationship read *and* mutation — per registered Resource with zero
hand-written controllers.

API Platform treats JSON:API as a first-class native format with its own
sub-package and MIME-type content negotiation, and that is genuinely more than
most frameworks offer. But its coverage is of JSON:API **1.0**, incompletely:
1.1-era mechanisms (`ext` extensions, the `profile` parameter, `lid`) are
unimplemented and untracked — a
["JSON:API v1.1 support" request (#8022)](https://github.com/api-platform/core/issues/8022)
has been open since 2021 — and even base-1.0 client-generated-ID support (§7.3)
only landed in mid-2026
([PR #7930](https://github.com/api-platform/core/pull/7930), merged 2026-06-05).

### Content negotiation (media-type parameters, 406/415 handling)

The spec's negotiation rules are asymmetric and easy to get wrong: an unsupported
`ext` on `Content-Type` must yield **415**, the same `ext` on `Accept` must yield
**406**, and `application/vnd.api+json` may carry only the `ext` and `profile`
parameters. The core implements exactly this (see
[content negotiation](https://github.com/haddowg/json-api/blob/main/docs/content-negotiation.md)),
along with strict query-parameter family validation on by default — an
unrecognized parameter family is a 400, not a silently-wrong 200. In core this
runs as PSR-15 middleware; this bundle drives the same logic through native
kernel listeners instead, so it sits where a Symfony developer expects.

API Platform's JSON:API negotiation is limited to base media-type matching
([docs](https://api-platform.com/docs/core/content-negotiation/)): no
`ext`/`profile` parameter negotiation exists (consistent with
[#8022](https://github.com/api-platform/core/issues/8022) remaining open), and
neither strict query-parameter behaviour nor the 415/406 asymmetry is documented
as a deliberate design choice.

### Extensions mechanism (`ext=` media-type parameter)

The core negotiates the `ext` media-type parameter strictly — an unsupported
extension is a 415 or 406 depending on which header carried it — with an empty
default supported set, and ships Atomic Operations as the first extension built
on the mechanism. API Platform has **no** `ext` negotiation mechanism at all; a
direct consequence of 1.1 support being unimplemented
([#8022](https://github.com/api-platform/core/issues/8022), open since June 2021).

### JSON:API format maintenance & stability signal

JSON:API is the *sole, purpose-built* output of these libraries — not a secondary
format alongside a different-first design. Every conformance decision is recorded
in a public ADR trail (131 decision records in core alone, plus the bundle's
own), and the test suites (149 test classes in core, 184 in the bundle) group
spec-requirement tests by spec section, so coverage is auditable
section-by-section.

API Platform's JSON:API support is actively maintained — 19 JSON:API-touching
PRs merged January–June 2026 — and it would be wrong to call it abandoned. But
the maintainer's own open RFC
([#8194](https://github.com/api-platform/core/issues/8194), May 2026) calls the
Symfony JSON:API query-parameter translation "brittle" and "the only remaining
JSON:API surface" not yet on the project's modern parameter architecture, and
multi-year conformance issues remain open
([#3042](https://github.com/api-platform/core/issues/3042) since September 2019,
[#8022](https://github.com/api-platform/core/issues/8022) since June 2021). A
fair reading: a functional but still-maturing secondary format inside a
Hydra/JSON-LD-first project. Note the flip side — API Platform's *overall*
project maturity is a genuine advantage, covered honestly
[below](#production-track-record-adoption--release-cadencegovernance).

---

## Atomic operations (the `atomic:operations` extension)

### `atomic:operations` extension — parsing, execution & results

The core ships the
[Atomic Operations extension](https://github.com/haddowg/json-api/blob/main/docs/atomic-operations.md)
end to end: a framework-agnostic parser, an ordered all-or-nothing execution
loop, `lid` resolution across operations within a batch, `atomic:results`
rendering, error `source.pointer` prefixing by operation index, and extension
advertisement through the `ext` negotiation above. This bundle adds the opt-in
`POST /operations` endpoint with genuinely transactional commit (a
transactional-persister seam so the whole batch commits or rolls back as one),
lifecycle hooks deferred until after commit, and a route-loader guard that fails
fast if the operations path would shadow a resource collection route — see
[atomic operations](atomic-operations.md).

API Platform has no implementation, no open feature request, and no discussion of
`atomic:operations` anywhere in its repository (an issue/PR search for the term
returns zero results). The closest analogue — generic JSON Patch support
([#759](https://github.com/api-platform/core/issues/759)) — has been open since
September 2016 with no roadmap commitment. If batched, transactional JSON:API
writes matter to your API, this dimension alone may decide the evaluation.

---

## Profiles (JSON:API 1.1 profiles)

### Profiles mechanism, registry & advertisement

Profiles — URI-identified, advisory extensions of document semantics — are a core
1.1 concept, and the library implements the whole mechanism: a `ProfileInterface`
with a per-Server `ProfileRegistry`, applied profiles advertised in
`jsonapi.profile`, echoed in the `Content-Type` `profile` parameter, and
accompanied by `Vary: Accept`. A schema-contributing seam lets a profile extend
the opt-in JSON Schema document validator, so a profile's additional members
validate rather than being rejected. The bundle registers and advertises profiles
as ordinary Symfony services.

API Platform has no `profile` media-type mechanism at all — profiles are absent
as a concept, consistent with the broader lack of 1.1 support
([#8022](https://github.com/api-platform/core/issues/8022)).

### Cursor-pagination profile (Ethan Resnick spec)

The core's `CursorPaginationProfile` advertises the published
[cursor-pagination profile URI](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/),
and a cursor-based Page activates it automatically when registered. This bundle
auto-registers and advertises it alongside the other bundled profiles.

In API Platform, a cursor-pagination-profile feature request
([#5063](https://github.com/api-platform/core/issues/5063)) was closed by the
stale bot in December 2022 without implementation — the sole closing activity is
the bot's comment — and there is no profile mechanism to build it on.

### Author-published/custom profiles (Countable, Relationship Queries)

Two further profiles ship in core and are auto-registered by this bundle: the
**Countable** profile (`?withCount=_self_,rel` returns `meta.total`, opt-in per
relation — see [relationships](relationships.md)) and the **Relationship
Queries** profile (filter and sort a relationship's linkage from the *primary*
request via `relatedQuery[rel][…]`), each with its own published spec document.
No analogous named JSON:API profiles — counting, relationship-scoped queries —
exist in API Platform, consistent with the absence of the mechanism itself.

---

## OpenAPI generation

### OpenAPI engine completeness & fidelity (paths, schemas, parameters from metadata)

The core includes a pure, framework-agnostic OpenAPI 3.1 projector that turns the
same Resource metadata driving the runtime into a complete document: CRUD,
relationship, related, and custom-action paths; context-correct create/update/read
schemas; the id policy; pagination/filter/sort/include parameters; reusable
component schemas. This bundle serves it live at `/docs.json` (per server, in a
multi-server app), warms it into the cache at deploy, exports it from the CLI,
and ships a Swagger UI / ReDoc viewer — see [openapi](openapi.md).

API Platform's general OpenAPI generator (v2/v3, Swagger UI/ReDoc/Scalar, CLI
export) is mature and a real strength of the project. Its *JSON:API-specific*
schema fidelity is the weaker path: a stream of fixes ran into mid-2026, a
duplicated-`data`-key collection-schema bug
([#6949](https://github.com/api-platform/core/issues/6949)) took roughly sixteen
months to fix (opened 2025-02, closed 2026-06), and the maintainer's own open
issue [#8322](https://github.com/api-platform/core/issues/8322) attributes
ongoing doc/response mismatches to the JSON:API schema generator
"reconstruct[ing] the document shape independently from the runtime serializer" —
a dual source of truth. Our projector and runtime consume the same metadata, and
the bundle round-trip-tests real responses against the generated schema (next
section), which is precisely the failure mode #8322 describes.

### OpenAPI response declarations & vendor-extension fidelity (async job lifecycle, self-describing pagination)

Each operation declares its real success-response set — Created, Ok, NoContent,
Accepted, SeeOther, meta-result, action-resource — so the generated spec matches
what the wire actually does, including the async write lifecycle (202/303, see
[async](async.md)). Vendor extensions carry what plain OpenAPI cannot:
`x-enum-varnames`/`x-enum-descriptions`, `x-profile` marking profile-gated
parameters, and explicit lossy-degradation notes where a constraint has no JSON
Schema analogue. Each Paginator self-describes its own `page[…]` schema, and a
Multi-paginator projects a discriminated `oneOf` menu of its strategies. The
bundle ships a schema-conformance test trait that asserts real responses against
the generated schema in your own suite.

API Platform has no self-describing pagination-strategy schema; its
vendor-extension richness lives on the Hydra/JSON-LD side, while JSON:API
response fidelity is the acknowledged weak path per
[#8322](https://github.com/api-platform/core/issues/8322).

---

## Typed TypeScript client (+ query-cache integration)

### Typed TypeScript client + query-cache integration

Client-side code is out of scope for the core package and this bundle themselves,
but the ecosystem ships it as a sibling project:
[`json-api-ts`](https://github.com/haddowg/json-api-ts) consumes the byte-stable
OpenAPI 3.1 document these libraries generate and produces a typed, JSON:API-aware
client — typed reads and writes, `?include`-hydrated result types, sparse-fieldset
narrowing — plus TanStack Query bindings with `type:id` cache normalization, a
published documentation site, and a
[hosted demo application](https://haddowg.github.io/json-api-ts/spotify-clone/).
Qualify it honestly: it is young, and a separate project rather than part of this
bundle.

API Platform's `create-client` is a different kind of tool: it scaffolds full CRUD
applications (Next.js, Vue, …) from Hydra or OpenAPI, and a separate generator
emits plain TypeScript interfaces
([docs](https://api-platform.com/docs/create-client/typescript/)). Neither output
is a typed JSON:API request client, and no React Query/TanStack/SWR integration is
offered. If a generated, cache-normalized JSON:API client matters to your
evaluation, the sibling project is the only offer on the table — weigh its youth
against that.

### Schema fidelity enabling third-party codegen for compound documents

What we *do* ship is the contract a third-party codegen tool needs to get
JSON:API right: standalone per-type JSON Schema 2020-12 documents, OpenAPI vendor
extensions, correct compound-document shape, and conformance tests proving the
schema matches real responses. On the API Platform side, a compound-document
OpenAPI regression (`included` missing from generated specs,
[#7956](https://github.com/api-platform/core/issues/7956)) was fixed in June
2026 — credit where due — but the maintainer-acknowledged structural risk remains
open ([#8322](https://github.com/api-platform/core/issues/8322)): the JSON:API
schema is generated independently of the serializer, so drift between spec and
response is an ongoing hazard rather than a one-off bug. A caveat in fairness:
neither project's schema has been empirically run through a specific third-party
generator as part of this comparison — the advantage here rests on our
conformance-tested pipeline versus their acknowledged drift risk.

---

## Filtering

### Declared filter vocabulary & operators

In the core, Filters are metadata-only value objects; an Adapter executes them —
so the vocabulary is portable across data layers. Core ships ten built-in filter
types (`Where`, `WhereIn`/`NotIn`, `WhereIdIn`/`NotIn`, `WhereNull`/`NotNull`,
`WhereHas`/`WhereDoesntHave`, `WhereThrough`) plus eleven convenience filters
(Boolean, Contains, DateRange, EndsWith, GreaterThan[OrEqual],
LessThan[OrEqual], Numeric, Range, StartsWith), with a reference in-memory
Adapter. This bundle translates all of them to DQL, adds Doctrine-only filters
(`WhereHasMatching`, pivot filters), and validates values for a clean 400 — see
[doctrine](doctrine.md).

API Platform has a rich, actively-modernized *general* filter catalogue
(ExactFilter, PartialSearchFilter, OrFilter, FreeTextQueryFilter, IriFilter —
[docs](https://api-platform.com/docs/core/filters/)). The JSON:API wiring is the
weak point: the maintainer's RFC
[#8194](https://github.com/api-platform/core/issues/8194) calls the Symfony
`filter[…]`/`page[…]` translation "brittle" and confirms "No
FilterParameterProvider (filter syntax) on either side" — on Laravel, bracket-form
`filter[name]=foo` is not natively wired at all.

### Filter value validation, defaults, fixed values & singular collapse

Declared filters carry value constraints — `constrain()`, `numeric()`,
`integer()`, `uuid()`, `boolean()`, `pattern()` — rejected with a clean 400
before the data layer is touched; `default()` supplies a value when the key is
absent, `fixed()` pins the value so the key becomes a pure presence trigger, and
`singular()` marks a filter whose match is zero-to-one. See
[the core filters doc](https://github.com/haddowg/json-api/blob/main/docs/filters.md).

API Platform's modern QueryParameter architecture supports parameter-level
validation in general, but per [#8194](https://github.com/api-platform/core/issues/8194)
the JSON:API filter surface on Symfony has not been ported to it (it still flows
through an inline mutation block), and on Laravel users hand-write
`#[QueryParameter(...)]` per model. No JSON:API-specific default/fixed/singular
mechanism was found in its docs — though we note that absence is inferred from
documentation coverage, not an exhaustive audit of their codebase.

### Author-composed filter groups (AND/OR/NOT combinators)

`WhereAll` (AND) and `WhereAny` (OR) compose child filters server-side into a
single named `filter[key]`: fan one value across columns for multi-column search,
build canned toggles from `fixed()` children, nest arbitrarily. The client
selects a named preset; it cannot assemble arbitrary boolean algebra — a
deliberate security and complexity boundary.

API Platform has real author-side composition primitives — `OrFilter` forces OR
combination, `FreeTextQueryFilter` fans one parameter across properties
([docs](https://api-platform.com/docs/core/filters/)) — but no
arbitrary-nesting AND/OR combinator tree with fixed-value children exposed as a
single named JSON:API `filter[key]` preset.

### Relationship-path traversal filters (dotted-path EXISTS semi-join)

`WhereThrough` walks a dotted relationship path (`filter[author.name]=Smith`) as
an EXISTS-ANY **semi-join** — never a fetch-join, so a to-many hop cannot
multiply result rows — with `WhereHas`/`WhereDoesntHave` as the existence case.
The bundle compiles these to correlated `EXISTS` subqueries.

Score this one *different approaches* rather than a clean win: API Platform
genuinely supports dotted-path filtering on related properties (dot notation on
ExactFilter, PartialSearchFilter, SortFilter, IriFilter — join-based, per its
docs). What it lacks is a relationship-*existence* primitive (its ExistsFilter
checks nullable values, not relationship presence) and documented semi-join
semantics avoiding row multiplication on to-many paths.

---

## Sorting

### Declared/computed multi-column sorting with defaults

`->sortable()` on a field auto-derives its sort; `sorts()` declares explicit or
computed multi-column sorts; the Adapter's sort handler receives the whole
ordered directive list in one call, so a strategy can act on the combination; and
`defaultSort()` applies only when the request carries no explicit `?sort`. The
bundle translates directives to sequential `addOrderBy` DQL terms in request
order, auto-derives pivot sort vocabulary, and appends the primary key as a
tiebreaker for stable cursor pagination.

API Platform supports the `sort=a,-b` convention with dotted nested-property
support, but per [#8194](https://github.com/api-platform/core/issues/8194) the
Symfony translation is an inline mutation block ("Symfony has no JSON:API
equivalent of SortFilterParameterProvider"), while on Laravel the JSON:API
SortFilter must be hand-attached per model.

---

## Pagination

### Pagination strategies offered (page/offset/fixed-page/cursor)

Four first-class strategies — `PagePaginator`, `OffsetPaginator`,
`FixedPagePaginator`, `CursorPaginator` — share one `PaginatorInterface` whose
window contract pushes down to `LIMIT`/`OFFSET` or a keyset `WHERE`; count-free
is the default. In this bundle, cursor pagination executes as a real keyset seek
on both data providers, across primary collections, related to-many endpoints,
pivot-backed `belongsToMany`, and relationship-linkage `GET` — see
[pagination](pagination.md).

API Platform offers page-based (default), partial (skip COUNT), and
cursor-based (`paginationViaCursor`) modes with client toggles
([docs](https://api-platform.com/docs/core/pagination/)). But its "cursor-based"
pagination is reported to execute as ordering plus `OFFSET`/`LIMIT` rather than a
true keyset seek ([#8033](https://github.com/api-platform/core/issues/8033),
where the project founder replies "I agree, this feature needs more polish and
more advanced options") — which forfeits the main reason to use cursors on large
collections. Bracket-form `page[…]` parsing also had a silent-drop bug whose 4.3
patch, per the maintainer's own RFC
([#8194](https://github.com/api-platform/core/issues/8194)), "papers over" the
issue rather than fixing the architecture.

### Client-selectable pagination-strategy menu (`page[kind]`)

A Multi-paginator composes several strategies server-declared; the client picks
one per request with `page[kind]=<kind>` (unknown kind → 400) or implicitly via a
strategy-unique key (`page[after]` implies cursor), falling back to the author's
declared default. The OpenAPI `page[…]` parameter projects as a single
`deepObject` `oneOf` schema per strategy, so the menu is machine-readable. API
Platform has no equivalent — the closest request
([#5063](https://github.com/api-platform/core/issues/5063)) was stale-bot-closed
in 2022.

### Cursor pagination on included/related collections

An included relation's cursor page renders correctly as a first page (keyset from
offset zero, id tiebreak, `hasMore` via a probe row), including the case of a
cursor-paginated included relation nested inside a page-based primary document —
with profile advertisement correct for the nested render. The bundle executes
this as batched, windowed queries per parent, not a query per row. API Platform
shows no evidence of SQL-level windowing to paginate included or nested to-many
relations at all (a code search for `ROW_NUMBER` in `api-platform/core` returns
zero results); the gap compounds the absence of the strategy menu above.

---

## Sparse fieldsets & includes

### Sparse fieldsets (`fields[type]` output narrowing)

`fields[TYPE]` narrows attributes and relationships per type (id always exempt),
with `notSparseField()` to pin a field into every rendering, and unknown sparse
fieldset members rejected rather than silently ignored. API Platform implements
`fields[type]` as a dedicated JSON:API component — per
[#8194](https://github.com/api-platform/core/issues/8194) it is "the only
JSON:API piece already on the new architecture" — but see the interaction bug in
the next row.

### Compound documents / includes — safeguards & N+1-safe batching

`?include` builds compound documents with deduplication and
primary-takes-precedence semantics; relationships can be included by default; and
three composable safeguards — `cannotBeIncluded()`, a maximum include depth, and
a root-scoped allowed-paths whitelist — guarantee termination against hostile or
accidental deep-include requests. The bundle batch-loads includes: one query per
relation per level, regardless of how many parents the page holds.

API Platform implements `include=…`, but a still-open bug
([#7267](https://github.com/api-platform/core/issues/7267), created July 2025)
reports that combining sparse fieldsets with `include` makes the entire
`included` section vanish — the two features that exist together in almost every
real JSON:API request. How severe this is in practice (workarounds, affected
versions) is not fully characterized, but the issue remained open at the time of
writing.

---

## Error handling

### Error catalogue & typed exception model

The core ships a fixed catalogue of 53 concrete typed exceptions, each
implementing one interface and fixing its own HTTP status, `code`, `title`,
`detail`, and `source` — exceptions carry error *data*, and the rendering layer
turns them into spec-shaped error documents. This bundle owns every failure on a
JSON:API route via one route-scoped `kernel.exception` listener with a three-arm
mapping (library exceptions natively, `HttpExceptionInterface` by status,
everything else a safe 500) — see [errors](errors.md).

API Platform has a JSON:API `ErrorNormalizer`, but
[#3042](https://github.com/api-platform/core/issues/3042) — open since September
2019, roughly seven years — documents that JSON:API-negotiated requests receive
RFC 7807 `application/problem+json` bodies rather than the spec's errors-array
format. A JSON:API client that negotiates the JSON:API media type and gets a
different format back on failure is a conformance problem of the first order.

### Stable machine-readable error codes

Every error in the catalogue carries a stable `SCREAMING_SNAKE` code
(`RESOURCE_NOT_FOUND`, …) independent of its human copy — the contract a client
codes against, never localized, never overridden. No stable machine-readable
error-code catalogue was found in API Platform's JSON:API output.

### Error message localization

Error `title`/`detail` are message templates resolved *by stable code*, with
`{placeholder}` interpolation and graceful fallback to the built-in English
catalogue. In core you bind an `ErrorMessageResolverInterface` on the Server; in
this bundle the copy localizes through the standard Symfony translator (the
`jsonapi_errors` domain), proven by an end-to-end localization test. API Platform
has no JSON:API-specific localization mechanism for error copy — consistent with
JSON:API errors not reliably rendering in the spec's own format
([#3042](https://github.com/api-platform/core/issues/3042)).

---

## Validation / JSON Schema

### Request/response document schema validation

The core's optional document validator (backed by `opis/json-schema`, a
dev-time/opt-in dependency, never required) validates decoded documents against
vendored JSON:API 1.1 draft-2020-12 meta-schemas, with separate request and
response roots and an `allOf` + `unevaluatedProperties` composition that lets
profile fragments *extend* the permitted members rather than being rejected.
This bundle adds a separate opt-in structural linter for write bodies, rendering
a clean 400 — see [validation](validation.md).

API Platform delegates request validation to the host framework (Symfony
Validator / Laravel Form Requests) rather than validating the JSON:API document
shape at request time; its Laravel validation path had a JSON:API-format bug
([#6745](https://github.com/api-platform/core/issues/6745), closed October
2024). Its JSON Schema *generation* is solid — see the next row, where we call
parity.

### Per-resource JSON Schema publication (create/update fragments, export)

Both projects do this well, and this row is parity. The core compiles a
Resource's field and Constraint metadata into per-type create/update
draft-2020-12 fragments (maxLength, pattern, enum, formats, composed and nested
constraints), with the honest caveat that some constraints deliberately don't
round-trip to JSON Schema and the docs say which. The bundle serves the schemas
over HTTP (`/schemas.json`) and from the CLI, sharing the same projector as
OpenAPI. API Platform's JSON Schema generation — CLI and programmatic factory,
including a JSON:API-specific factory
([docs](https://api-platform.com/docs/core/json-schema/)) — is a genuine strength
independent of the request-time validation gap above.

---

## Framework fit

The framing that matters here: a library should feel **native in the stack you
already chose**. These libraries are one JSON:API model with idiomatic
integrations — a Symfony developer gets services, kernel listeners, Doctrine, and
`security:` expressions; a developer on a custom stack gets a pure PSR core. API
Platform is likewise an agnostic core with framework bridges, so the comparison
below is about the depth and evenness of each bridge, not the architecture.

### Framework-agnostic core (PSR-7/15/17, zero mandatory coupling)

The core's runtime dependencies are PHP 8.3 plus six PSR packages
(`psr/container`, `psr/http-factory`, `psr/http-message`,
`psr/http-server-handler`, `psr/http-server-middleware`, `psr/log`) — nothing
else; even the JSON Schema validator is optional. A bare Server behind any
PSR-15 dispatcher is a complete JSON:API service. API Platform's core
metadata/serialization layer is also framework-agnostic in principle, with
Symfony and Laravel as separate bridges over the same metadata — architecturally
a similar model, and we score it parity.

### Native Symfony/Doctrine integration depth

This bundle is a deep native integration: resource discovery by service
autoconfiguration, the lifecycle as kernel listeners, a reference Doctrine ORM
data layer giving zero-handler CRUD for any mapped entity,
[capability composition](capability-composition.md) (serializer / hydrator /
relations / provider / persister as independent optional capabilities),
multi-server support, custom non-CRUD [actions](actions.md), an async write
lifecycle (202/303), declarative `security:` expressions, and a 184-class test
suite run against both an in-memory and a Doctrine/SQLite provider so behaviour
is conformance-checked, not provider-specific.

Symfony + Doctrine is also API Platform's original and most mature integration —
the reference implementation the whole project grew from, with a large ecosystem
and a long production track record. The honest verdict is *different strengths*:
for JSON:API-specific depth on Symfony, the feature comparison above favours this
bundle dimension by dimension; for general-purpose API tooling with years of
hardening and a large community, API Platform's Symfony integration is ahead.
Which weighs more depends on whether JSON:API is your API's contract or one
format among several.

### Native Eloquent/Laravel integration depth

A sibling package, [`haddowg/json-api-laravel`](https://github.com/haddowg/json-api-laravel),
exists and is actively developed, but this page's scope is the core plus the
Symfony bundle, so no claims are made here about its feature depth — evaluate it
directly if Laravel is your stack.

API Platform's Laravel bridge is real and actively developed, with an uneven
JSON:API surface by the maintainer's own account
([#8194](https://github.com/api-platform/core/issues/8194)): its
`JsonApiProvider` is "stripped down: only handles include", there is "No
FilterParameterProvider… No PageParameterProvider on either side", and "users
still hand-write `#[QueryParameter(...)]` on each model". In fairness, Laravel is
*ahead* of Symfony on one axis — it has a JSON:API sort provider on the modern
parameter architecture where Symfony does not — and a UUID-primary-key/IRI
generation bug ([#8167](https://github.com/api-platform/core/issues/8167))
remained open at the time of writing.

---

## Performance

### N+1 avoidance for relationship linkage, counts & compound includes

Relationship linkage is lazy by default: a to-many relation renders links-only
rather than forcing a load just to serialize ids, gated by a storage-aware
load-state seam. Relationship counts batch across the fetched page of parents —
one grouped query per relation, not one per row — and the bundle batch-loads
`?include` and default includes as one query per relation per level.

API Platform's `EagerLoadingExtension` force-joins readable associations by
default (capped at 30, configurable) to avoid N+1
([docs](https://api-platform.com/docs/core/performance/)) — a mature,
long-standing mechanism that has needed corrective fixes over time (e.g.
[#5992](https://github.com/api-platform/core/issues/5992), missing eager joins on
to-one relationships). The design difference: force-joining everything readable
trades query breadth for round trips; lazy linkage plus targeted batching fetches
only what the response actually needs.

### SQL push-down / windowing for paginated included or nested to-many relations

The core gives Adapters a shared, storage-agnostic seam for `LIMIT`/`OFFSET` and
keyset push-down — including for related-collection endpoints and included
relations. This bundle runs windowed includes as **one** native
`ROW_NUMBER() OVER (PARTITION BY parent …)` query per relation with a real
per-parent total (with a configurable per-parent `LIMIT`/`OFFSET` fallback for
older database engines), and compiles relationship-existence and traversal
filters to correlated `EXISTS`, never a fetch-join — see
[pagination](pagination.md) and [doctrine](doctrine.md). API Platform shows no
evidence of SQL-level windowing for paginated included or nested to-many
relations anywhere in its codebase or docs.

### Count-free pagination by default

Both projects get this right, differently defaulted. Here, pagination is
count-free by default (a limit+1 probe drives `next`; no `COUNT` unless the
author opts in with `withCount()` or the client asks under the Countable
profile), with client page sizes capped at 100 by default. API Platform's partial
pagination mode explicitly skips COUNT queries as a documented performance
feature ([docs](https://api-platform.com/docs/core/pagination/)). Parity on
capability; our default is the cheap path, theirs is a mode you enable.

### Streaming serialization for large collection responses

Neither side earns a clean claim here. These libraries have no dedicated
streaming serializer for large responses — count-free cursor pagination is the
intended mitigation for large collections. API Platform's v4.2.0 introduced a
"json streamer" for streaming serialization of large responses, but whether and
how it applies to JSON:API output specifically is unconfirmed by any report we
could verify. If you need streamed multi-megabyte single responses rather than
paginated ones, evaluate that feature directly against your format.

---

## Testing utilities

### JSON:API-format-specific test assertions & helpers

The core ships testing utilities in the runtime autoload (usable from any
consumer suite): fluent `JsonApiDocument`/`JsonApiErrors` assertions,
request/operation builders, and a one-line spec-compliance assertion that
validates a rendered document against the JSON:API schema. This bundle adds
`JsonApiBrowser` (a `KernelBrowser` subclass with `assertFetchedOne`,
`assertCreated`, …), the `InteractsWithJsonApi` trait, and the
schema-conformance trait that asserts your real responses against your generated
OpenAPI — see [multi-server and testing](multi-server-and-testing.md).

API Platform ships excellent test clients (next row), but no
JSON:API-format-specific helpers or assertions: a code search for
`assertJsonApi` in `api-platform/core` returns zero results.

### General test-client maturity

API Platform's advantage, plainly: `ApiTestCase` on Symfony and the Laravel test
client are mature, general-purpose, format-agnostic tools with JSON-Schema-based
assertions, hardened by years of use. These libraries ship nothing comparable —
our testing utilities are purpose-built around JSON:API and assume the host
framework's own HTTP test client underneath. If you need one test harness across
several output formats, theirs is the better general tool.

---

## Maturity, adoption & production track record

### Production track record, adoption & release cadence/governance

API Platform wins this dimension, and it matters. Its latest stable is v4.3.16
(released 2026-07-03), backed by [Les-Tilleuls.coop](https://les-tilleuls.coop/)
with a long project history, broad community adoption, an ecosystem of
integrations, and a documented per-version support matrix. These libraries are
young: actively and rapidly developed, with an unusually thorough public decision
trail and test discipline, but without an established public production track
record, adoption numbers, or years of releases to point to. If your evaluation
weights ecosystem maturity and the ability to hire developers who already know
the tool, API Platform is the safer bet today; if it weights JSON:API fidelity
and depth, the rest of this page is the counter-argument.

---

## Multi-protocol / format flexibility

### Multi-protocol output (Hydra/JSON-LD, GraphQL) & MCP agent-tool exposure

API Platform generates Hydra/JSON-LD, GraphQL, and JSON:API from a single
resource metadata layer, and an experimental MCP component (present on `main`)
exposes API resources and operations as tools to AI agents. If you need several
protocols from one model, that is its core value proposition and nothing in these
libraries competes with it.

These libraries are deliberately single-format: no Hydra/JSON-LD, no GraphQL, no
MCP output exists or is planned. That is a scope decision — depth over breadth —
and it is exactly *why* the JSON:API-specific dimensions above come out the way
they do: extensions, profiles, atomic operations, format-faithful OpenAPI, and a
spec-section-by-spec-section test discipline are what a dedicated library spends
its complexity budget on. Choose by what your API's contract is: if JSON:API is
the contract, a dedicated library serves it better; if JSON:API is one of several
formats you must emit, API Platform is built for that.

---

## Summing up

Choose **API Platform** when you need multi-protocol output from one metadata
layer, when its ecosystem maturity and production track record are decisive, or
when JSON:API is a secondary format your API merely also speaks — its JSON:API
support is real, actively maintained, and improving (client-generated ids and the
compound-document OpenAPI fix both landed in 2026).

Choose **`haddowg/json-api` + this bundle** when JSON:API *is* your API's
contract. Full 1.1 conformance with a published test-backed compliance table,
the `ext`/`profile` mechanisms API Platform has not implemented, atomic
operations, true keyset cursor pagination (including on included relations, with
a client-selectable strategy menu), a typed error catalogue with stable codes and
localization, and OpenAPI generated from the same metadata that renders the
response — delivered idiomatically in Symfony: services, kernel listeners,
Doctrine, `security:` expressions, and zero hand-written controllers.
