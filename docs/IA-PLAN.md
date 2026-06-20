# Bundle docs — Phase 1A deliverable: IA, capability matrix & example-app spec

> Workflow output (feature-surface audit verified against `src/` + the ADR decision surface → synthesised into a reading journey backed by one worked Symfony+Doctrine example app). For maintainer sign-off before Phase 1B (build the example app) and Phase 1C (write the docs).

These are the docs for **`haddowg/json-api-symfony`** — the Symfony bundle that makes the framework- and storage-agnostic **`haddowg/json-api`** core idiomatic in a Symfony application. The cardinal rule of this IA: **the bundle docs build ON the core docs.** Every JSON:API *concept* — the field DSL, the relation DSL, the constraint vocabulary, the response value objects, the document model, content negotiation, the exception catalogue — is owned by core and **linked**, never re-explained. The bundle pages document only what the bundle *adds*: how a Symfony app installs it, how Symfony discovers and wires JSON:API types, the route loader, the kernel-listener lifecycle, route-scoped error rendering, the Doctrine data layer, the `DataProvider`/`DataPersister` SPI, the Symfony Validator bridge, configuration, capability-composition wiring, the config-declared multi-server feature (ADR 0034), security/deployment posture, and testing with `KernelTestCase`.

Core doc link base (forward-correct for the v1 push; core `main` is not yet pushed):
`https://github.com/haddowg/json-api/blob/main/docs/<page>.md`
Core source: `https://github.com/haddowg/json-api/blob/main/src/...`
Core example: `https://github.com/haddowg/json-api/blob/main/examples/music-catalog/...`

---

## 1. Reading-journey rationale

### Who reads this, and in what order

Three reader profiles, in descending frequency:

1. **The Symfony integrator (the 90%)** — has a Symfony+Doctrine app, wants spec-compliant JSON:API endpoints over their entities with the least ceremony. They arrive knowing Symfony (bundles, services, autowiring, attributes, the kernel, the validator) and knowing little or nothing about `haddowg/json-api`. Their journey is **install → register a resource → get endpoints → query/write → validate → trim/customise**. They should reach a working read endpoint within the first two pages and never need to read core internals to ship.
2. **The data-layer author** — needs a store other than Doctrine, or needs to scope/shadow the Doctrine reference (tenant scoping, soft-delete, a custom provider for a polymorphic to-many). They live in the **SPI** pages.
3. **The capability composer / advanced integrator** — needs a wire shape the field DSL can't express (custom serializer/hydrator), a resource-less type, per-relation endpoint trimming, or handler decoration. They live in the **capability-composition** and **advanced** pages.

### How it dovetails with the core docs

The core docs teach **what a JSON:API type *is*** — `AbstractResource`, the fields DSL, relations, constraints, serializers, hydrators, the response VOs, the document model. The bundle docs teach **how Symfony runs it for you**: the same `AbstractResource` you'd hand-register on a core `Server` is here discovered by autoconfiguration; the same operation handler you'd hand-write in core is here a single generic `CrudOperationHandler` driven by kernel listeners; the same constraint VOs core declares-but-never-executes are here translated to Symfony Validator rules and actually enforced.

Concretely, every bundle page that touches a core concept opens with the Symfony-specific affordance and then **hands off** to the core page for the vocabulary. `resources.md` (bundle) teaches discovery and the `#[AsJsonApiResource]` attribute, then links core `resources.md` and `fields.md` for *what goes inside* `fields()`. `validation.md` (bundle) teaches the optional bridge and the 422 rendering, then links core `constraints.md` for the constraint vocabulary it translates. This keeps the bundle docs small and drift-resistant: when core's field DSL grows, the bundle docs don't change.

The journey has **six arcs**, each a section: (1) **Getting started** — what the bundle is, install (the unusual `dev-main` install), and one end-to-end music-catalog endpoint from an empty Symfony app. (2) **Wiring & discovery** — how Symfony finds and assembles a type (resources, the attribute, the operation allow-list, standalone capabilities, configuration). (3) **The request lifecycle** — the route loader, the three kernel listeners, content negotiation, and route-scoped error handling. (4) **The data layer** — the SPI, the Doctrine reference adapter, the query-extension seam, the in-memory provider, and the generic CRUD handler. (5) **Validation** — the Symfony Validator bridge and the optional opis linter. (6) **Advanced & cross-cutting** — capability composition, custom serializers/hydrators, relationship endpoints, config-declared multi-server, security/deployment, and testing.

Each page opens with the simplest real music-catalog use, layers options, then branches to nuance. Reference tables (the config tree, the route-name table, the constraint-translation table, the tag/priority matrix, the optional-dependency matrix) come **after** the worked example on every page that has one. The example app — `examples/music-catalog-symfony/` — is the single source of truth: every snippet is extracted from a CI-run `KernelTestCase`, so the docs cannot drift, exactly as core's own example app enforces.

---

## 2. Proposed information architecture (17 pages, 6 sections)

> Note on theme: the bundle docs use the **same music-catalog theme** as core (Artist, Album, Track, Playlist, User, Favorite, Library, Chart) so a reader moving between the two doc sets sees one continuous domain. Where core's example is an in-memory PHP app, the bundle's is a Symfony+Doctrine app over the same eight domains (**8 domains = 7 Doctrine-entity-backed `AbstractResource` types + 1 store-backed serialize-only `Chart`** — no entity, no resource).

### Section A — Getting started

---

**`index.md`** — haddowg/json-api-symfony: spec-compliant JSON:API for Symfony

*Role:* Docs front door. Audience: a Symfony developer evaluating the bundle. One-paragraph identity, the relationship to core, the install caveat stated once, a short taste, and the map into the rest of the docs.

*Outline (progressive disclosure):*
- One-paragraph identity: a Symfony bundle that turns `haddowg/json-api` into idiomatic Symfony — discovery by autoconfiguration, a route loader, a kernel-listener lifecycle, route-scoped error rendering, and a reference Doctrine data layer.
- The core relationship stated up front: the **core library owns the JSON:API model** (fields, relations, constraints, serializers, response VOs); **this bundle owns the Symfony integration**. A "you will be reading both doc sets" signpost, with the core docs index **and core `concepts.md`** linked (the shared mental model the bundle pages assume).
- Pre-1.0 instability warning (breaking changes between `0.x` minors; release-please-driven changelog).
- Requirements: PHP 8.3/8.4/8.5 (the bundle uses `public const string` typed constants — 8.3 is a hard floor), Symfony 6.4 || 7.x; hard runtime deps `nyholm/psr7` + `symfony/psr-http-message-bridge` (the PSR-7 ↔ HttpFoundation bridge the listeners use).
- Install caveat stated ONCE: core is not yet on Packagist, so install is the global Composer path/VCS-repo dance for `dev-main` (→ install.md); becomes a plain `^1.0` at core v1.
- A 12-line taste: an `AlbumResource` (Id + Str) as a service + `$routes->import('.', 'jsonapi')` → a working `GET /albums` — pointing forward, not copy-paste-runnable.
- Optional-capability map: Doctrine, validation, strict email, the opis linter are all opt-in `suggest` deps (→ the optional-dependency matrix on configuration.md).
- Docs map: the six sections, in reading order.

*Capabilities:* Project identity & the core relationship, Requirements, Pre-1.0 warning, Install caveat (once), Docs map.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/index.md` (what core is), `https://github.com/haddowg/json-api/blob/main/docs/concepts.md` (the shared JSON:API mental model the "you'll read both doc sets" signpost points at), `https://github.com/haddowg/json-api/blob/main/docs/getting-started.md` (the core mental model).

**Bundle-specific additions:** The whole page — the framing of "bundle builds on core", the PHP/Symfony version floors, the runtime PSR-7-bridge deps, and the docs map are all bundle-only.

---

**`install.md`** — Installation and bundle registration

*Role:* The unusual install, done right. Audience: anyone adding the bundle. Owns the `dev-main` path/VCS-repo dance and bundle registration — the steps the rest of the docs assume are done.

*Outline (progressive disclosure):*
- The headline caveat: this is **not yet a normal `composer require`** — core `haddowg/json-api` is unpublished and required as `dev-main`.
- The path-repo recipe (local dev): `composer config -g repositories.haddowg-json-api '{"type":"path","url":"/abs/path/to/json-api","options":{"symlink":true}}'` against a sibling checkout kept on its `main` branch (a path repo reports `dev-<branch>`; only `dev-main` satisfies the constraint).
- The VCS-repo recipe (CI / no sibling checkout): a global VCS repository resolving `dev-main` from GitHub.
- The forward note: at core v1 this collapses to `composer require haddowg/json-api-symfony` with a `^1.0` core pin and no repository stanza.
- Register the bundle: add `haddowg\JsonApiBundle\JsonApiBundle::class => ['all' => true]` to `config/bundles.php` (the bundle ships none; flag that the test kernels register it imperatively via `registerBundles()` and an app uses `bundles.php`).
- `symfony/framework-bundle` is required (the bundle relies on its services).
- The required next step, signposted hard: **routes are not auto-mounted** — you must import the route type (→ routing.md). Many readers will stop after registering the bundle and wonder why there are no endpoints.
- The optional deps, named with one line each and a pointer to the matrix: `doctrine/orm`, `symfony/validator`, `egulias/email-validator`, `opis/json-schema` (→ configuration.md matrix).

*Capabilities:* Install & bundle registration, the `dev-main` path/VCS-repo dance, FrameworkBundle requirement, the "routes are a separate step" signpost, the optional-dependency teaser.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/index.md` (core install / not-on-Packagist note).

**Bundle-specific additions:** Everything — the path/VCS-repo dance, `bundles.php` registration, the FrameworkBundle requirement, and the routes-are-separate warning are all bundle concerns.

---

**`getting-started.md`** — Your first music-catalog endpoint

*Role:* The canonical end-to-end onboarding walkthrough, Symfony edition. Audience: a first-time user. Builds a fetch + create `albums` endpoint over Doctrine in a real Symfony app, test-verified.

*Outline (progressive disclosure):*
- The pieces YOU provide in Symfony: a Doctrine entity (`Album`), an `AbstractResource` registered as a service with `#[AsJsonApiResource(entity: Album::class)]`, the route import, and config (`base_uri`).
- The pieces the BUNDLE provides: discovery, routing, negotiation, the lifecycle, error rendering, the Doctrine read/write path — no controller, no handler, no serializer by hand.
- Step 1 — the Doctrine `Album` entity (id + title + a couple of fields).
- Step 2 — `AlbumResource extends AbstractResource`: `$type='albums'`, `fields()` with `Id` + `Str` (one declaration drives serialize AND hydrate) — link core for what `fields()` accepts.
- Step 3 — register it as a service (autowire + autoconfigure) and map the entity with `#[AsJsonApiResource(entity: Album::class)]`; note that autoconfiguration tags any `AbstractResource` automatically.
- Step 4 — configure: `json_api: { base_uri: 'https://localhost' }` and import the routes: `$routes->import('.', 'jsonapi')` (YAML `resource: '.' type: jsonapi`).
- Step 5 — three worked HTTP outcomes with the JSON:API media type: `GET /albums` → 200 collection; `POST /albums` → 201 + Location; `GET /albums/999` → 404 — all rendered as JSON:API documents by the bundle.
- The "what just happened" recap mapping each outcome to the lifecycle stage that produced it (→ lifecycle.md) and the Doctrine provider that fetched it (→ doctrine.md).
- Where to go next: the section hub.

*Capabilities:* `AbstractResource` minimal in Symfony, `#[AsJsonApiResource(entity:)]`, service registration + autoconfiguration, the route import, `base_uri` config, the three worked outcomes (200/201/404) over Doctrine.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/resources.md` and `https://github.com/haddowg/json-api/blob/main/docs/fields.md` (what goes in `fields()`), `https://github.com/haddowg/json-api/blob/main/docs/getting-started.md` (the core equivalent, for contrast).

**Bundle-specific additions:** Service registration + autoconfiguration, the entity mapping attribute, the route import, the config, and the zero-handler/zero-controller end-to-end flow — none of which exist in core.

---

### Section B — Wiring & discovery

---

**`resources.md`** — Resources, discovery & the `#[AsJsonApiResource]` attribute

*Role:* How Symfony discovers and configures a JSON:API type built on `AbstractResource`. Audience: the 90% user. The on-ramp; forward-links capability composition for the resource-less model.

*Outline (progressive disclosure):*
- Zero-config discovery: any service whose class extends core's `AbstractResource` is auto-tagged `haddowg.json_api.resource` (`registerForAutoconfiguration`). Register the service → get the full endpoint set (resource default = all five operations).
- The default behaviour: a registered resource exposes all five CRUD operations and full relationship endpoints; later pages trim.
- The `#[AsJsonApiResource]` attribute, argument by argument: `type` (declaration-site override of static `$type`), `entity` (Doctrine entity mapping → doctrine.md), `serializer`/`hydrator` (per-type overrides → custom-serializers-hydrators.md), `operations` (the allow-list → routing.md), and `server` (the named server(s) this type is exposed on — a single name, a list of names, or unset for the implicit `default`; ADR 0034 → multi-server-and-testing.md). The attribute also tags a non-`AbstractResource` class as a resource.
- `$type` vs `$uriType`: the JSON:API type vs the URL segment; `$uriType` defaults to `$type`, read statically (no instantiation). One illustrative line in prose (a `book` type served at `/books`); `custom-serializers-hydrators.md` is the canonical owner of `uriType` (forward-link there).
- Why overrides exist: the field DSL can't always express the wire shape (forward link).
- The compile-time guards a reader will hit (all `LogicException` at container build, not request time): unregistered/wrong-type serializer or hydrator override; declaring a write without a hydrator; entity-mapping faults (→ doctrine.md owns the entity-map ones).
- Branch: override one capability, or skip the resource entirely (→ capability-composition.md).

*Capabilities:* Resource discovery + `RESOURCE_TAG`, `#[AsJsonApiResource]` all arguments (including the real `server` assignment), `$type` vs `$uriType` (the illustrative `book → /books`, owned canonically by custom-serializers-hydrators.md), the all-five default, compile-time guards overview.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/resources.md` (what `AbstractResource` is), `https://github.com/haddowg/json-api/blob/main/docs/fields.md`, `https://github.com/haddowg/json-api/blob/main/docs/field-types.md`, `https://github.com/haddowg/json-api/blob/main/docs/ids.md`, `https://github.com/haddowg/json-api/blob/main/docs/relations.md` (what goes inside the resource).

**Bundle-specific additions:** Autoconfiguration discovery, the `#[AsJsonApiResource]` attribute and every argument (including `server` assignment, ADR 0034), the Symfony service-registration model, and the compile-time guard surface — all bundle-only. `$uriType` is core, but the route-emission consequence is bundle (illustrated in prose here, owned by custom-serializers-hydrators.md).

---

**`capability-composition.md`** — Composing a type from independent capabilities

*Role:* The capability-composition model in Symfony: serializer / hydrator / relations / provider / persister as independent, optionally-resource-less capabilities, each registered by an attribute. Audience: a user beyond the `AbstractResource` on-ramp. Owns the standalone-registration story and the default-operations asymmetry.

*Outline (progressive disclosure):*
- The thesis: a JSON:API type is a set of independent capabilities; `AbstractResource` is pure Symfony-side sugar bundling serializer + hydrator + relations. Which endpoints exist falls out of which capabilities a type declares: no provider → no reads; no hydrator/persister → no writes; serialize-only → just a serializer.
- The three standalone attributes (all `TARGET_CLASS`, keyed by `type`), each autoconfiguring its public tag: `#[AsJsonApiSerializer(type, operations)]` → `SERIALIZER_TAG` (`haddowg.json_api.serializer`) on a core `SerializerInterface` (serialize-only by default — the classic embedded/reference type — opens endpoints only via `operations`); `#[AsJsonApiHydrator(type)]` → `HYDRATOR_TAG` (`haddowg.json_api.hydrator`) on a core `HydratorInterface` (a type is writable iff a hydrator is registered and a persister is wired); `#[AsJsonApiRelations(type)]` → `RELATIONS_TAG` (`haddowg.json_api.relations`) on the bundle's `RelationsProviderInterface` (declares relations for a resource-less type, feeding `RelationsRegistry`).
- The **default-operations asymmetry**, stated side by side as a footgun: an `AbstractResource` defaults to all five operations; a standalone `#[AsJsonApiSerializer]` defaults to **none** (serialize-only) until `operations` opens them. The allow-list *mechanism* (the `Operation` enum, how a verb becomes a route, the unrouted-verb 404/405) is owned by routing.md — this page owns the per-capability *defaults*; cross-linked both ways so the two never drift.
- A single class may carry multiple attributes (one class can be both serializer and hydrator).
- The mix-and-match recipes: a serialize-only embedded type (serializer alone); a read-only fetchable type (serializer with `operations` + a provider); a write-only ingest (hydrator + persister, no serializer); a fully resource-less CRUD type (serializer + hydrator + relations + provider + persister).
- The compile-time guard: exposing Create/Update without a hydrator throws `LogicException` at build (`ResourceLocatorPass::validateWriteCapability`) with a fix hint.
- How relations differ from serializers/hydrators in wiring: relations are runtime objects (not container-dumpable scalars), so they resolve lazily by type through `RelationsRegistry` rather than a class-string locator (brief; the *why* belongs here, the *what* in routing/relationships).

*Capabilities:* The capability-composed type model, `#[AsJsonApiSerializer]`/`#[AsJsonApiHydrator]`/`#[AsJsonApiRelations]` and their public tags (`SERIALIZER_TAG` / `HYDRATOR_TAG` / `RELATIONS_TAG` = `haddowg.json_api.serializer` / `.hydrator` / `.relations`), the default-operations asymmetry, multi-attribute classes, the write-without-hydrator guard, the `RelationsRegistry` lazy-by-type rationale.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/capability-composition.md` (the core thesis), `https://github.com/haddowg/json-api/blob/main/docs/serializers.md`, `https://github.com/haddowg/json-api/blob/main/docs/hydrators.md`, `https://github.com/haddowg/json-api/blob/main/docs/relations.md`.

**Bundle-specific additions:** The three Symfony attributes and their type-keyed registration, the autoconfiguration tags, the Symfony-side default-operations asymmetry, and the build-time write-capability guard. The *thesis* is core's; the *Symfony wiring of it* is bundle.

---

**`configuration.md`** — Configuration reference (`json_api:`) and optional dependencies

*Role:* The (intentionally tiny) config tree, the container parameters it surfaces, and the optional-dependency matrix. Audience: anyone configuring the bundle. The reference page.

*Outline (progressive disclosure):*
- The tree is four keys (declared inline in `AbstractBundle::configure()` — there is **no** `Configuration.php` / `Extension` class; the extension alias `json_api` is auto-derived from the bundle name): `base_uri` (scalar, default `''`), `version` (scalar, default `'1.1'`), `schema_validation` (bool, default `false`), and the `servers` map.
- What each does: `base_uri`/`version` are surfaced as container params `haddowg_json_api.base_uri` / `haddowg_json_api.version` and define the implicit `default` server's `ServerFactory`; `schema_validation` conditionally registers the opis structural linter and **throws a `LogicException` at build** if enabled without `opis/json-schema` (→ validation.md).
- **The `json_api.servers` map (ADR 0034):** the top-level `base_uri`/`version` define the implicit **`default`** server (so a single-API app needs no `servers:` block at all). The optional `servers:` map declares **additional named servers**, each with its own `base_uri`/`version` that **inherit the top-level value when omitted**. Each declared server gets one `ServerFactory` (id `haddowg.json_api.server_factory.<name>`); the full server-name list is surfaced as `haddowg_json_api.servers`. The reserved-name guard: a named server may **not** be literally `default` (that name is the implicit top-level server) — a build-time `LogicException`. Worked snippet: an `admin` server under `/admin` with its own `base_uri`. Server *assignment* (which types join which server) is on the resource attribute (→ resources.md); per-server route mounting is in routes.yaml (→ routing.md); end-to-end resolution is on multi-server-and-testing.md.
- The optional-dependency matrix (TABLE): each `suggest` dep → what it enables → the degradation when missing. `doctrine/orm` → the reference provider/persister (absent → no Doctrine path; entity mapping inert); `symfony/validator` → the constraint bridge (absent → writes run **unvalidated, silently**); `symfony/doctrine-bridge` → the `UniqueEntity` entity rule (absent → `UniqueEntity` cannot be translated; → validation.md); `egulias/email-validator` → strict email (absent → strict silently degrades to HTML5); `opis/json-schema` → the schema linter (absent + `schema_validation: true` → build fails). `symfony/doctrine-bridge` is only transitively present via `doctrine/doctrine-bundle`; it **should be added to the bundle's own `composer.json` `suggest`** so the `UniqueEntity` runtime requirement is discoverable — listed in the matrix here and stated at the witness in validation.md.
- The conditional-wiring note: most capability wiring is via service tags + autoconfiguration, not config — so this page is short by design and points at the SPI/validation pages for the tag model.

*Capabilities:* The `json_api` config tree (4 keys incl. the `servers` map), the container parameters, the multi-server config map (implicit `default` + named servers + the reserved-name guard, ADR 0034), the optional-dependency / degradation matrix (incl. the `symfony/doctrine-bridge` → `UniqueEntity` row), the "wiring is tags not config" pointer.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/server.md` (what `base_uri`/`version` configure on each core `Server`), `https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md` (what the opis linter does).

**Bundle-specific additions:** The entire config tree, the parameter names, the `json_api.servers` map and its build-time guards, and the optional-dependency matrix are all bundle concerns.

---

### Section C — The request lifecycle

---

**`routing.md`** — The route loader and operation-gated routes

*Role:* How the bundle turns discovered types into Symfony routes. Audience: anyone wiring endpoints. Owns the route import, the generated route set, the operation allow-list, and the `Target` resolver seam for explicit-route users.

*Outline (progressive disclosure):*
- The one required step: import the custom route type — `$routes->import('.', 'jsonapi')` (`JsonApiRouteLoader::ROUTE_TYPE === 'jsonapi'`). The `resource` argument is **not a path/glob** — types come from the compiled `ResourceLocatorPass` descriptors. But it is **not** ignored: it **names the server** (ADR 0034). The bare `.` / empty / `default` import emits the **`default`** server's routes; a non-empty, non-`.` string (`$routes->import('admin', 'jsonapi')`, YAML `resource: admin`, `type: jsonapi`) emits that **named** server's routes. Prefix/host/condition stay in routes.yaml where Symfony users expect them — the import's `prefix('/admin')`/`host()` apply to the emitted paths. An unknown/empty server emits nothing.
- The generated route set (TABLE): per type, `jsonapi.{type}.index` (`GET /{seg}`), `.create` (`POST /{seg}`), `.show` (`GET /{seg}/{id}`), `.update` (`PATCH /{seg}/{id}`), `.delete` (`DELETE /{seg}/{id}`), where `{seg}` is `uriType`. Route **names** key on the JSON:API type; **paths** use `uriType` (may differ — `uriType` is owned by custom-serializers-hydrators.md, forward-linked).
- The per-server route-name scheme (ADR 0034): the **`default`** server keeps the existing **unprefixed** names `jsonapi.{type}.{action}`; a **named** server namespaces them `jsonapi.{server}.{type}.{action}` (e.g. `jsonapi.admin.albums.show`) so a type exposed on two servers never collides. Each route is stamped `_jsonapi_server => <name>` so the lifecycle resolves the right server.
- Router-native, no catch-all: one literal path per type, so the router 404s unknown types itself — the documented stance. Per-route security/firewall therefore works normally.
- The operation allow-list: the public `Operation` enum (five cases, value === name so it survives container dumping) gates which routes a type serves. Defaults: resource = all five; standalone serializer = none (the **default-operations asymmetry** is taught in full on capability-composition.md — cross-linked both ways so the route-emission mechanism here and the per-capability defaults there never drift). Set via `operations:` on the resource/serializer attribute. An unexposed verb is **unrouted** (router 404/405, no handler reached). Note operations round-trip through DI as comma-joined strings; unrecognised values are silently dropped.
- Relationship & related routes (for any type with relations — resource or `#[AsJsonApiRelations]`): `.relationship.show/.update/.add/.remove` (`…/relationships/{rel}`) and `.related.show` (`/{type}/{id}/{rel}`). The segment-count ordering that prevents shadowing (4-segment linkage registered before 3-segment related; neither shadows `/{type}/{id}`). **Relationship routes are NOT gated by the `Operation` allow-list** — per-relation exposure governs those (→ relationships.md).
- Route defaults each route carries: `_controller => JsonApiController`, `_jsonapi_type`, `_jsonapi_server => <the import's server name>` (`default` for the bare import, the named server otherwise), `_jsonapi => true` (the error-listener marker); relationship routes add `_jsonapi_relationship_endpoint`.
- The explicit-route seam: `TargetResolver::resolveFromRequest(Request): ?Target` — a pure mapper (no container/IO) for hand-written routes. The doc trap, stated firmly: calling `TargetResolver` alone is **insufficient** — a hand-router must also set all four/five route defaults and resolve to a controller returning the stashed VO, or the lifecycle won't engage.

*Capabilities:* The per-server route import (`ROUTE_TYPE`, `resource: <server>`), the generated route-name/URL table, the per-server route-name scheme (unprefixed `default` vs namespaced `jsonapi.{server}.…`), router-native 404 stance, the `Operation` enum + allow-list + defaults (defaults taught on capability-composition.md, cross-linked), relationship/related routes + shadowing order, route defaults (incl. `_jsonapi_server`), `TargetResolver` as the explicit-route seam.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/operations.md` (the `Operation\Target` the bundle builds + the verb×target-shape dispatch), `https://github.com/haddowg/json-api/blob/main/docs/related-endpoints.md` and `https://github.com/haddowg/json-api/blob/main/docs/relationship-mutation.md` (what the relationship routes serve), `https://github.com/haddowg/json-api/blob/main/docs/server.md` (the per-server `Server` the `_jsonapi_server` default resolves to).

**Bundle-specific additions:** The route loader, the per-server `'jsonapi'` import, the per-server route-name scheme (unprefixed `default` vs namespaced named servers), operation-gated emission, the route-defaults contract (incl. `_jsonapi_server`), and `TargetResolver` are entirely bundle. The `Operation` enum is the bundle's DX wrapper over core's operation model.

---

**`lifecycle.md`** — The request lifecycle: kernel listeners over `Server::dispatch()`

*Role:* The central mental model. Audience: any integrator wanting to understand how a request becomes a JSON:API response — and why the profiler/firewall/logging wrap it normally. Owns the three-listener flow and content negotiation's call sites.

*Outline (progressive disclosure):*
- The headline: this bundle does **not** run core's PSR-15 `Middleware\*` chain. It drives core's lifecycle *logic* directly from three native Symfony kernel listeners, so the profiler, firewall and logging behave as on any Symfony endpoint.
- `RequestListener` (`kernel.request`, priority **16** — after Symfony's `RouterListener` at 32, so route defaults are populated): on a route carrying `_jsonapi_type`, it resolves the `Target`, resolves the server via `ServerProvider::get($request->attributes->get('_jsonapi_server'))` (so a per-server route reaches its own `Server`; → multi-server-and-testing.md), converts the Symfony Request to PSR-7 (Nyholm) and wraps it as a core `JsonApiRequest`, negotiates + validates, builds the operation via core's `OperationFactory`, calls **`Server::dispatch()`** (the PSR-15-bypassing entry point), and stashes the response VO on request attributes (sets no `Response`, so `kernel.view` runs).
- `JsonApiController`: a no-op pass-through returning the stashed `AbstractResponse` VO (needed only because HttpKernel requires a controller; throws `LogicException` if the listener produced nothing).
- `ViewListener` (`kernel.view`, default priority): renders the stashed VO to PSR-7 via `AbstractResponse::toPsrResponse()` (the serializer-free render seam — builds the document array + `json_encode` with `JSON_THROW_ON_ERROR`), then bridges PSR-7 → HttpFoundation. This is where `application/vnd.api+json` reaches the response.
- The request-attribute contract (so an integrator sees how the stages talk): `_jsonapi_response`, `_jsonapi_resolved_server`, `_jsonapi_psr_request`.
- Content negotiation & body validation, attributing the **rules** to core but owning the **call sites**: `RequestValidator::negotiate()` (415/406), `validateQueryParams()` (400), and on write verbs `validateJsonBody()` (400). The bundle-specific decisions: which verbs carry a body (POST/PATCH always; a relationship-endpoint DELETE carries `{data:[…]}`; a resource DELETE does not), and the skip of `validateTopLevelMembers()` for relationship writes (a linkage body may legitimately be `null`/`[]`).
- The client obligation: clients must send the JSON:API media type — the bundle adds no default Accept/Content-Type (core enforces it).
- The optional opis structural linter runs here when `schema_validation: true` (→ validation.md).

*Capabilities:* The three-listener lifecycle, listener priorities (16 / default / 128), `Server::dispatch()` over PSR-15, the `JsonApiController` pass-through, `toPsrResponse()` render seam, the request-attribute contract, content-negotiation/body-validation call sites + the body-carrying-verb branch, the media-type client obligation.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/architecture.md` and `https://github.com/haddowg/json-api/blob/main/docs/middleware.md` (the PSR-15 lifecycle this bundle replaces with listeners), `https://github.com/haddowg/json-api/blob/main/docs/content-negotiation.md` (the negotiation/validation rules), `https://github.com/haddowg/json-api/blob/main/docs/responses.md` (the response VOs the view listener renders).

**Bundle-specific additions:** The entire listener model, the priorities, the `Server::dispatch()` (not `handle()`) choice, the pass-through controller, the request-attribute keys, and the body-carrying-verb decisions are bundle. Core owns the negotiation rules and the render seam.

---

**`errors.md`** — Route-scoped error handling

*Role:* How every failure on a JSON:API route becomes a spec-compliant error document. Audience: anyone producing or debugging errors. Owns the `ExceptionListener` mapping and the debug gating.

*Outline (progressive disclosure):*
- The model: `ExceptionListener` on `kernel.exception` at priority **128** (wins over framework error handling) and **route-scoped** — it acts only when the matched route carries `_jsonapi` (`ROUTE_MARKER`), so non-JSON:API routes are untouched and the bundle never hijacks the rest of the app.
- The three mapping arms: (1) a core `JsonApiExceptionInterface` renders via its own `getErrors()/getStatusCode()` through `ErrorResponse::fromException()`; (2) a Symfony `HttpExceptionInterface` (firewall 401/403, routing 404, 405, …) maps to a status-keyed `Error` with a reason-phrase title (the bundle's own `match()` table: 400/401/403/404/405/406/409/415/422/5xx) and a debug-only detail; (3) anything else → 500 via core's stateless `InternalServerError::for($throwable, $debug)` — byte-identical to core's own middleware 500.
- Debug gating, stated explicitly: `{exception,file,line,trace}` meta and the detail are redacted when `kernel.debug` is off (no leaking secrets); on when it's on (`%kernel.debug%`).
- The firewall interplay: because 401/403 are `HttpException`s and the listener is route-scoped, a security exception on a JSON:API route still renders as a JSON:API document — so per-route firewall + JSON:API error rendering compose.
- Logging: unexpected throwables are logged via the **optional** Symfony `logger` service (`nullOnInvalid`) — so 500 logging is silently absent if no logger exists (integrators relying on it need monolog/a logger).
- The two code paths recap (bundle `match()` table for HTTP exceptions vs core `InternalServerError::for()` for the generic 500) and which to expect when.

*Capabilities:* Route-scoped `ExceptionListener` (priority 128, `ROUTE_MARKER`), the three mapping arms, the reason-phrase table, debug-gated meta/detail, firewall interplay, optional-logger behaviour, the byte-identical-500 guarantee.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md` (the exception catalogue, `ErrorResponse`, `Error`/`ErrorSource`, `InternalServerError::for`, `JsonApiExceptionInterface`).

**Bundle-specific additions:** The route-scoping, the listener priority, the `HttpExceptionInterface` → status mapping with the reason-phrase table, the debug gating wiring, the firewall interplay, and the optional-logger behaviour are bundle. The catalogue and the 500 shape are core.

---

### Section D — The data layer

---

**`data-layer.md`** — The Provider/Persister SPI and the generic CRUD handler

*Role:* The storage-agnostic read/write SPI that drives every endpoint, and how a request flows through it. Audience: anyone overriding the data layer or wanting the mental model. The hub for Section D.

*Outline (progressive disclosure):*
- The model: data access is storage-agnostic over two tagged SPIs resolved **per type** — `DataProviderInterface` (reads) and `DataPersisterInterface` (writes) — and a single generic `CrudOperationHandler` drives all nine operations over them. No per-type handler code.
- `DataProviderInterface` (read SPI): the four methods + exact signatures — `supports(string $type): bool`; `fetchOne(string $type, string $id): ?object` (null → 404); `fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult`; `fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult`. The `@template-covariant TEntity` contract (single-type provider is `<Album>`, multi-type is `<object>`). Tagged `DATA_PROVIDER_TAG` by autoconfiguration; a type with no provider has no read endpoints.
- `DataPersisterInterface` (write SPI): the six methods — `supports()`, `instantiate(string $type): object` (a blank instance for the hydrator — the persister owns the storage mapping so it owns instantiation, ADR 0010), `create()`/`update()` returning the entity, `delete(): void`, and `mutateRelationship(string $type, object $entity, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, bool $flush = true): object`. The `$flush` subtlety (ADR 0018): relationship endpoints flush per mutation; a whole-resource write applies embedded relationships with `flush:false` so the single `create()`/`update()` owns the commit. Entities flow as plain `object`. Tagged `DATA_PERSISTER_TAG`.
- **Resolution: priority + first-supports-match** — the override recipe. Services arrive in descending tag `priority` (default 0); the registry returns the first whose `supports()` is true; the bundled Doctrine provider/persister register at **-128** (always the fallback). So an app provider at default priority shadows Doctrine for its types with **no config**. Priority is a tag attribute, not registry behaviour (the registry trusts the injected `tagged_iterator` order). No match = `LogicException` (a wiring bug, never a 404).
- `CollectionCriteria` + `CriteriaApplier`: the criteria VO — exact signature `CollectionCriteria::__construct(QueryParameters $queryParameters, array $filters = [], array $sorts = [], ?WindowInterface $window = null)` — and the shared applier that decides spec semantics once (folds filter defaults via core's `FilterDefaults`, throws `FilterParamUnrecognized`/`SortingUnsupported`/`SortParamUnrecognized` as 400s, handles the `-` descending prefix, passes sorts as one composite call). **Pagination windowing is the provider's job**, not the applier's — this is what keeps in-memory an attributable witness for Doctrine. `CollectionResult` (`->items` + `->total`, non-null exactly when windowed, the count *before* windowing).
- The `CrudOperationHandler` flow: operation dispatch (fetch one/collection, related, relationship, create/update/delete, three relationship mutations); the write flow's relationship-stripping subtlety (`withoutRelationships()` before core hydrates id+attributes, then `mutateRelationship(... Mode::Replace, flush:false)`); what it renders (201+Location from `uriType`/`type`, 200, 204 `NoContentResponse`); the optional validation hooks (→ validation.md); the singular-filter collapse (`SupportsSingular` → zero-to-one `DataResponse::fromResource`, core ADR 0039); and that customization composes through the SPIs / serializer-hydrator overrides / **decorating this handler** (→ advanced.md).
- `TypeMetadataResolver`: the seam that tolerates a bare serializer/hydrator pair (no resource, no field inventory) without per-type branching — `resourceFor()` returns `?AbstractResource` (null for a bare pair), so filters/sorts/pagination/validation are skipped on that path.

*Capabilities:* `DataProviderInterface` (4 methods + covariance), `DataPersisterInterface` (6 methods + `$flush`/`instantiate`), priority/first-match resolution + the -128 fallback, `CollectionCriteria`/`CriteriaApplier`/`CollectionResult`, the `CrudOperationHandler` flow + relationship-strip + render statuses + singular collapse, `TypeMetadataResolver`.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/filters.md`, `https://github.com/haddowg/json-api/blob/main/docs/sorts.md`, `https://github.com/haddowg/json-api/blob/main/docs/pagination.md` (the filter/sort/window vocabulary the applier executes), `https://github.com/haddowg/json-api/blob/main/docs/adapters.md` (`FilterHandlerInterface`/`SortHandlerInterface`), `https://github.com/haddowg/json-api/blob/main/docs/hydrators.md` (`ToOneRelationship`/`ToManyRelationship`/`Mode`), `https://github.com/haddowg/json-api/blob/main/docs/responses.md` and `https://github.com/haddowg/json-api/blob/main/docs/operations.md` (the response VOs + operations the handler dispatches).

**Bundle-specific additions:** Both SPI interfaces, the priority/first-match registry + -128 convention, `CollectionCriteria`/`CriteriaApplier`/`CollectionResult`, the `CrudOperationHandler`, the relationship-stripping write flow, and `TypeMetadataResolver` are all bundle. Core owns the filter/sort/window vocabulary and the response VOs.

---

**`doctrine.md`** — The Doctrine reference data layer

*Role:* The zero-config default for entity-mapped types. Audience: the Doctrine integrator. Owns the entity mapping, the filter/sort translation, related-collection scoping, constructor-less instantiation, and the load-state seam.

*Outline (progressive disclosure):*
- The wiring: active only when `doctrine/orm` is installed AND at least one resource maps an entity via `#[AsJsonApiResource(entity: …)]`. `DoctrineEntityMapPass` builds the `type → entity-class` map at compile time and **removes** the Doctrine provider/persister/load-state definitions when the map is empty (so a non-Doctrine app never references an absent `EntityManagerInterface`). Build-time faults a reader hits: missing entity class, undeterminable type, one type mapped to two entities — all `LogicException`.
- The read path: `fetchCollection` is one `QueryBuilder` pipeline (extensions → `CriteriaApplier` filter/sort → COUNT over the filtered un-ordered query → LIMIT/OFFSET window, never over-fetched); `fetchOne` runs the same extension pipeline (so a scope holds for `GET /{type}/{id}`) and falls back to `EntityManager::find()` (identity-map fast path) only when no extension supports the type. Only an `OffsetWindow` is executable.
- The write path: `update()` relies on the target being a **managed** instance the hydrator mutated in place (loaded via the same EntityManager) — just `flush()`; no persist/merge. The coupling callout: a custom provider returning a **detached** entity for `fetchOne` would silently break Doctrine updates — provider and persister must share the EntityManager (they do). `create()` is persist+flush.
- Constructor-less instantiation (ADR 0029): `instantiate()` uses `ClassMetadata::newInstance()`, so entities with required constructor args work under the generic engine — but **constructor invariants/defaults do not run on create** (consistent with read-hydration). An app needing them overrides `instantiate()` via a custom persister. (Note the toMany-collection re-init that avoids "accessed before initialization" on a fresh create.)
- The filter vocabulary that translates to DQL (TABLE): `Where` (operator map; `like` = case-insensitive ASCII contains via `LOWER()` + `ESCAPE`), `WhereIn`/`WhereNotIn`/`WhereIdIn`/`WhereIdNotIn` (empty-list semantics), `WhereNull`/`WhereNotNull`, `WhereHas`/`WhereDoesntHave` (correlated `EXISTS`/`NOT EXISTS` subquery — set-membership, no join, no DISTINCT, to-one and to-many translate identically, ADR 0019). Anything else → core `UnsupportedFilter`.
- The sort vocabulary: only `SortByField` translates (`addOrderBy`, request order preserved); anything computed/multi-column → core `UnsupportedSort` (declare your own handler/provider).
- The security stance: columns come from the server-side resource declaration (never the client), are regex-validated as DQL field paths before interpolation, values are always parameter-bound (the `jsonapi_` prefix is collision-free / reserved).
- Related-collection scoping (ADR 0031): the two branches of `fetchRelatedCollection` for a to-many — a single-valued inverse association (OneToMany, related entity carries the FK) scoped by that FK (fast path); any other to-many (owning-side / many-to-many) scoped by an `IN` subquery rooted on the parent, keeping the related entity as the outer root so filter/sort/count/window apply identically. The hard boundary: a **polymorphic to-many** (`MorphToMany`) throws `LogicException` — members span entity classes, so supply a custom provider (→ relationships.md, advanced.md).
- The load-state seam (ADR 0015): `DoctrineRelationshipLoadState` powers `dataOnlyWhenLoaded()` — a to-many is "loaded" only when its `PersistentCollection` is initialised; a to-one is always loaded (its proxy carries the id). In non-Doctrine apps the seam is null (core's always-loaded default).

*Capabilities:* `DoctrineEntityMapPass` + the entity-map build (+ faults + empty-map removal), the read pipeline + COUNT-before-window, the managed-update coupling, constructor-less `instantiate()`, the DQL filter/sort translation tables, the column-safety stance, related-collection FK-vs-IN-subquery scoping + the polymorphic-to-many boundary, the Doctrine load-state seam.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/filters.md` and `https://github.com/haddowg/json-api/blob/main/docs/sorts.md` (the filter/sort VOs these handlers execute), `https://github.com/haddowg/json-api/blob/main/docs/pagination.md` (`OffsetWindow`), `https://github.com/haddowg/json-api/blob/main/docs/relations.md` (`RelationInterface`/`MorphToMany`/load-state), `https://github.com/haddowg/json-api/blob/main/docs/adapters.md` (the handler interfaces the Doctrine handlers implement).

**Bundle-specific additions:** Everything — the entity map, the Doctrine provider/persister, the DQL translation, the related-collection scoping, constructor-less instantiation, and the Doctrine load-state predicate are all bundle reference-implementation code.

---

**`custom-data-providers.md`** — Custom providers, query extensions & the in-memory provider

*Role:* The how-to for the SPI: override Doctrine per type, scope its queries, or replace it entirely. Audience: the data-layer author. Owns `DoctrineExtensionInterface`, the in-memory provider as a worked example, and the override recipe.

*Outline (progressive disclosure):*
- The override recipe, restated as a how-to: implement `DataProviderInterface` (and/or `DataPersisterInterface`), return true from `supports()` for your type, let autoconfiguration tag it at default priority (0) — it shadows the -128 Doctrine fallback for just that type. A custom provider SHOULD reuse `CriteriaApplier` to stay spec-conformant (→ data-layer.md).
- **Reference / static-data providers (the simplest case):** a type whose data is not in the database at all — a fixed list, a dataset from a library like `symfony/intl`, or a backed enum's cases. A tiny `DataProvider` (`supports()` + `fetchOne()`/`fetchCollection()` returning the static rows) paired with a standalone `#[AsJsonApiSerializer]` exposes it **read-only with no entity, no hydrator, no persister** — the minimal "expose arbitrary data as JSON:API" path. The example app's `countries` resource sources its rows from `symfony/intl`'s `Countries` and still serves filter/sort/pagination by reusing `CriteriaApplier` over the in-memory list (so a non-DB source is a first-class collection, not a special case); the docs note the even-simpler variant — a backed enum (e.g. a `Genre` enum exposed as `genres`, id = case value) — for the truly fixed case.
- `DoctrineExtensionInterface` — the query-scoping seam for base constraints the client must not undo (soft-delete, tenant scoping, published-only) and query shaping (eager-load joins): `supports(string $type): bool` and `apply(QueryBuilder $builder, string $type, QueryPurpose $purpose): QueryBuilder`. Discovered by autoconfiguration (`DOCTRINE_EXTENSION_TAG`); every matching extension runs in descending priority **before** the requested criteria — so client filter/sort always AND on top, the COUNT is taken from the scoped builder, and an out-of-scope single-fetch row becomes a 404.
- The `QueryPurpose` fail-closed contract: the enum (`FetchCollection`, `FetchOne`) is **non-exhaustive by design** — apply unconditionally and branch on a purpose only to *exempt* one (an exhaustive `match` would silently stop applying when a new purpose appears). The param-naming rule: any bound-param name not prefixed `jsonapi_` is safe. The builder arrives with the root entity selected (and, for `FetchOne`, the id constraint already bound). The canonical worked example: a tenant/category scope.
- The in-memory provider as the reusable worked example: `InMemoryDataProvider`, `InMemoryDataPersister`, `InMemoryStore` live in **`src/`** (not tests) precisely so they're a documented example (mirroring core shipping its `InMemory` array handlers). Construction: `new InMemoryDataProvider(string $type, iterable $itemsKeyedById, ?Closure $identify)` (the `identify` closure is required only for writes); pair with `new InMemoryDataPersister(string $type, InMemoryStore $store, Closure $factory, ?Closure $relatedResolver)` sharing the **same** store via `$provider->store()` so a create is immediately readable. `$factory` builds the blank create instance; `$relatedResolver` `(type,id) → ?object` is **required for relationship mutation** (a persister with no resolver supports only whole-resource writes and throws if asked to mutate a relationship). The exact service-tag wiring (factory service + the two tags) as the copyable pattern.
- The `$request` arg subtlety: `fetchRelatedCollection` passes a `JsonApiRequestInterface` the Doctrine provider **ignores** (push-down) but the in-memory provider **uses** (to `relation->readValue()`, so a custom `extractUsing` extractor can consult it) — a custom provider author needs to know it's available.
- Replacing Doctrine for a polymorphic to-many: the escape hatch — the Doctrine provider throws for a `MorphToMany`; a custom provider resolves members across types (the example app demonstrates this, → example-app spec).

*Capabilities:* The custom-provider override recipe + `CriteriaApplier` reuse, the reference/static-data provider pattern (a `symfony/intl`-sourced list or a backed enum as a read-only resource with no entity/hydrator/persister), `DoctrineExtensionInterface` + the before-criteria order + COUNT/404 consequences, the `QueryPurpose` fail-closed contract + param-naming rule, the in-memory provider/persister/store construction + tag wiring + shared store, the `$relatedResolver` requirement, the `$request`-in-`fetchRelatedCollection` note, the polymorphic-to-many escape hatch.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/adapters.md` (the `FilterHandlerInterface`/`SortHandlerInterface` semantics a custom provider composes; core's `ArrayFilterHandler`/`ArraySortHandler` that the in-memory provider delegates to), `https://github.com/haddowg/json-api/blob/main/docs/relations.md` (`readValue`/`extractUsing`).

**Bundle-specific additions:** The whole page — the override recipe, `DoctrineExtensionInterface`, `QueryPurpose`, and the in-memory provider/persister/store are all bundle. Core owns only the array filter/sort handlers the in-memory provider delegates to.

---

### Section E — Validation

---

**`validation.md`** — The Symfony Validator bridge and the optional schema linter

*Role:* How the bundle executes core's declared-but-never-run constraint vocabulary. Audience: anyone validating writes. Owns the bridge wiring, the document/entity passes, the translation table, the 422 rendering, the extension point, and the (separate) opis structural linter.

*Outline (progressive disclosure):*
- The headline + the silent-absence callout: validation is an **optional bridge** (`symfony/validator` is `suggest`). When installed, the bridge runs automatically on POST/PATCH; when **not** installed, `CrudOperationHandler`'s validator resolves to null and **writes run unvalidated, silently** — a prominent doc warning so a reader never assumes declared constraints are enforced by default.
- The second gating callout: validation only runs for an `AbstractResource`-backed type (`TypeMetadataResolver::resourceFor()` returns `?AbstractResource`) — a type assembled from a **standalone serializer/hydrator pair** declares no field inventory, so writes through it are not validated. A real behavioural gap a capability-composition user must know.
- The document-first pass (`ResourceValidator::validate()`, before hydration): validates the request `data.attributes` array (so pointers map cleanly) via a Symfony `Collection(allowExtraFields: true)`. The per-field wrapping rules, spelled out: a create-required field → `Required([...])`, relaxing to `Optional([...])` on update (PATCH may omit, but a supplied value must be non-empty); a non-nullable present field gets `NotNull` prepended; a nullable field passes `NotBlank(allowNull)`. Presence is mandatory only on create; PATCH never requires a member. **`Required`/`Nullable` are resolved here against create/update `Context`, NOT translated** — the translation table and the presence/nullability mechanics are two separate mechanisms presented together. Id/relation fields and read-only fields are skipped; unknown attributes ignored (matching the hydrator).
- The 422 rendering: each violation → a core `Error` (status `422`, code `VALIDATION_FAILED`, detail = the Symfony message, `source.pointer` built by `JsonPointerBuilder` from the bracketed property path, RFC-6901-escaped); all violations collected and thrown together as one `ValidationFailed` (a core `AbstractJsonApiException`, status 422), rendered by the route-scoped exception listener. A worked 422 body with pointers. (Relies on core's error-document status fidelity so a uniform 422 bag stays 422.)
- The constraint-translation table (TABLE): the full core-VO → Symfony-constraint map (`In`→`Choice`, `Min`→`GreaterThanOrEqual`, `MinLength`→`Length`, `MinItems`→`Count`, `EmailFormat`→`Email`, `Pattern`→`Regex`, `Each`→`All`, `Sequentially`/`AtLeastOneOf` direct, etc.) — plus the nuances (regex delimiter `~`; `Url` protocol/`requireTld` defaults; `Uuid` version restriction; the `MinLength`/`MaxLength` clamps; `MinProperties`/`MaxProperties` collapsing to `Count`). The constraint VOs are **core** — link core for the vocabulary, document only the Symfony mapping here.
- Closure-carrying constraints via `Callback`: `When` (re-validates inner constraints only when the condition holds), `After`/`Before`/`Between` (coerce to `\DateTimeImmutable`, skip absent/unparseable values, resolve a closure bound at validation time — so "now" reflects the request, exercised under a frozen `symfony/clock`).
- `CompareField` — document-level cross-field comparison (evaluated after the Collection pass because it needs the sibling value; the six `Comparison` operators; skipped if either field is absent/null; 422 pointing at the owner field).
- The entity-level pass (`validateEntity()`, after hydration, before commit): collects field constraints implementing the bundle marker `EntityConstraintInterface` (filtered by `Context`), translates them to Symfony **class** constraints, validates the hydrated entity. The bundled `UniqueEntity` VO (`->constrain(new UniqueEntity(['email']))`) → doctrine-bridge `UniqueEntity` (queries the repository, excludes the current record on update). **Stated at this witness:** `UniqueEntity` requires **`symfony/doctrine-bridge`** at runtime (the bridge ships Symfony's `UniqueEntity` constraint + validator). The example app's `composer.json` therefore depends on `symfony/doctrine-bridge` (brought transitively by `doctrine/doctrine-bundle`); the bundle should also list it in its own `composer.json` `suggest` (→ configuration.md matrix).
- The extension point: `ConstraintTranslatorInterface` (autoconfigured `CONSTRAINT_TRANSLATOR_TAG` only when validator is installed) — register a translator (`supports()`/`translate(): list<Constraint>`, descending priority, first match wins) for your own constraint VO; unmatched → a clear `LogicException` naming the class. The typed replacement for the removed `$id`-keyed `Custom` hatch (matches on class). (An entity-level custom rule uses the same mechanism but the VO also implements `EntityConstraintInterface`.) **Prose-only — not in the example app.**
- The nested-`Map` cascade (ADR 0020): a `Map` attribute's direct children validate by implicit recursion (no `Valid` marker) → `/data/attributes/<map>/<child>` pointers; the scope limit stated firmly — **one level deep only** (a `Map`-in-`Map` or list-of-objects is not descended).
- Strict-email degradation: `EmailFormat(strict)` → `Email(mode: STRICT)` only if `egulias/email-validator` is installed; otherwise it **silently degrades to HTML5**.
- The optional opis structural linter (`json_api.schema_validation`, default false), kept **distinct**: structural **400** (is this a well-formed JSON:API document?) running in `RequestListener` before the handler, vs the semantic **422** bridge (do values satisfy the constraints?). Requires `opis/json-schema`; enabling without it fails the build. The opis pieces are **core** classes (`DocumentValidator`/`VendoredSchemaProvider`) merely wired by the bundle — link core for the validator itself.

*Capabilities:* The optional bridge + silent-absence + standalone-pair gaps, the document-first pass (Required/Nullable/Context resolution, allowExtraFields), the 422 + `JsonPointerBuilder` rendering, the full translation table, `When`/date-bounds via `Callback`, `CompareField` document-level, the entity-level pass + `EntityConstraintInterface` + `UniqueEntity` (+ doctrine-bridge gap), `ConstraintTranslatorInterface` extension point, the `Map` one-level cascade, strict-email degradation, the opis 400-vs-422 split.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/constraints.md` (the constraint vocabulary + `Context` + `constrain()` this bridge translates), `https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md` (`Error`/`ErrorSource`/`AbstractJsonApiException`), `https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md` (`DocumentValidator`/`VendoredSchemaProvider` — the opis linter), `https://github.com/haddowg/json-api/blob/main/docs/fields.md` (the `Map` field).

**Bundle-specific additions:** The entire bridge — wiring, the two passes, `JsonPointerBuilder`, the translation table, `Callback`/`CompareField` handling, `EntityConstraintInterface`/`UniqueEntity`, the `ConstraintTranslatorInterface` extension point, the `Map` cascade, and strict-email degradation — is bundle. Core owns the constraint VOs, the `Error`/`ErrorSource` model, and the opis classes.

---

### Section F — Advanced & cross-cutting

---

**`relationships.md`** — Relationship endpoints in the bundle

*Role:* How the bundle serves and mutates relationships, and the per-relation exposure gates. Audience: anyone exposing relationship endpoints, including polymorphic and paginated related collections. Owns the bundle-side enforcement; links core for the relation DSL.

*Outline (progressive disclosure):*
- The two read endpoints per relation: related (`GET /{type}/{id}/{rel}`) and relationship/linkage (`GET …/relationships/{rel}`), emitted for any type with relations (resource or `#[AsJsonApiRelations]`). Linkage + self/related `links` render by convention (default on; `withoutLinks()` opt-out). An empty to-one renders `data: null`. `?include` flows through both endpoints. (The relation DSL and these behaviours are core — link.)
- Paginated related to-many collections (ADR 0030): `GET /{type}/{id}/{rel}` for a to-many honours `?filter`/`?sort`/`?page` against the **related** type's vocabulary via `DataProvider::fetchRelatedCollection()`; per-relation default pagination resolves relation → related-resource → server default. The Doctrine push-down (FK fast-path vs `IN` subquery) is owned by doctrine.md; the in-memory provider reads off the parent and applies `CriteriaApplier` + an array window.
- Relationship **mutation** (`PATCH`/`POST`/`DELETE …/relationships/{rel}`): core validates the request shape (cardinality → 400, mutability flags → 403); the bundle's `DataPersister::mutateRelationship()` (the canonical six-arg signature is in data-layer.md — linked, not restated here) applies it storage-correctly (Doctrine → managed reference + FK write; in-memory → the stored object, via the `$relatedResolver`). The same seam is reused for relationships embedded in whole-resource writes (ADR 0018; → data-layer.md). Empty linkage = clear.
- Per-relation endpoint exposure (ADR 0027), enforced **handler-side** (routes stay parametric, emitted once per type): `withoutRelatedEndpoint()` → related GET is 404 (`RelationshipNotExists`); `withoutRelationshipEndpoint()` → relationship GET/mutate is 404; `cannotAdd()` → POST add is 403 (`AdditionProhibited`); `allowsReplace()`/`allowsRemove()` gate PATCH/DELETE (`FullReplacementProhibited`/`RemovalProhibited`). The exposure flags are **core** (`RelationInterface`); the bundle owns the enforcement and **omits the convention link to a suppressed endpoint** so a rendered link never points at a 404.
- Polymorphic endpoints (ADR 0032): a `MorphTo` to-one resolves its serializer from the related object (empty → `data: null`); a `MorphToMany` renders mixed members via a `PolymorphicSerializer`. The provider split: in-memory supports a polymorphic to-many (reads the mixed collection; `filter`/`sort` 400 since there's no shared vocabulary, `page` slices); the **Doctrine provider throws** "unsupported" for a polymorphic to-many — supply a custom provider (→ custom-data-providers.md, the example app demonstrates this).
- Standalone relations (ADR 0026): `#[AsJsonApiRelations(type)]` on a `RelationsProviderInterface` declares relations for a resource-less type, feeding `RelationsRegistry`; `TypeMetadataResolver` sources relations resource-first then from the registry, so a standalone-relations type gets identical relationship routes and rendering (→ capability-composition.md for the wiring rationale).

*Capabilities:* The two read endpoints + convention links + empty-to-one `data:null` + `?include`, paginated related to-many over `fetchRelatedCollection()` + the resolution chain, relationship mutation over `mutateRelationship()` + clear semantics, per-relation exposure 404/403 + link omission, polymorphic to-one/to-many rendering + the in-memory/Doctrine split, standalone relations via `#[AsJsonApiRelations]`.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/relations.md` (the relation DSL + exposure/mutability flags + load-state), `https://github.com/haddowg/json-api/blob/main/docs/related-endpoints.md` (the read endpoints + polymorphic rendering + `PolymorphicSerializer`), `https://github.com/haddowg/json-api/blob/main/docs/relationship-mutation.md` (the mutation model + the 400/403 exceptions), `https://github.com/haddowg/json-api/blob/main/docs/links-and-meta.md` (the convention self/related `links` + `withoutLinks()` omission this bundle renders), `https://github.com/haddowg/json-api/blob/main/docs/sparse-fieldsets-and-includes.md` (`?include`).

**Bundle-specific additions:** The handler-side exposure enforcement + link omission, `mutateRelationship()` storage application, the paginated-related-collection provider seam, the in-memory-vs-Doctrine polymorphic split, and `#[AsJsonApiRelations]`/`RelationsRegistry` are bundle. Core owns the relation DSL, the read-endpoint semantics, and the mutation exception model.

---

**`custom-serializers-hydrators.md`** — Custom serializers, hydrators & handler decoration

*Role:* The advanced escape hatches in Symfony: override a type's serializer/hydrator, register them standalone, customise `uriType`, or decorate the global handler. Audience: a user the field DSL can't model, or who needs cross-cutting handler behaviour.

*Outline (progressive disclosure):*
- Per-resource override (ADR 0023): `#[AsJsonApiResource(serializer: …, hydrator: …)]` keeps the resource's type/route/registration role but delegates the wire shape to a hand-written `SerializerInterface`/`HydratorInterface`. The overrides **must be registered services** (so they can have constructor dependencies) — `ResourceLocatorPass` throws `LogicException` if not registered or wrong type. The declared fields become inert (the override owns I/O); the generic engine drives reads/writes through the override.
- Standalone registration (ADR 0024): `#[AsJsonApiSerializer]`/`#[AsJsonApiHydrator]` with no resource (→ capability-composition.md for the model). Note here the consequence for I/O: a bare pair declares no field inventory, so no validation, no filters/sorts (→ validation.md, data-layer.md).
- `uriType` (ADR 0022) — **this page is the canonical owner** (resources.md and routing.md forward-link here): a resource's URL segment distinct from its type via static `$uriType` (the illustrative `book` type served at `/books`). Only the path changes — route names, `_jsonapi_type`, dispatch, and the rendered `type` member keep the JSON:API type; the Location header on create and convention links use `uriType` (falling back to type for a bare pair). **Docs-prose only:** core's example domain does not override `uriType`, so the bundle example app does **not** add a `uriType` divergence — `uriType` is illustrated here in prose, never witnessed in the example app.
- Handler decoration (ADR 0028): customise the single global `CrudOperationHandler` by **Symfony service decoration** — `#[AsDecorator(CrudOperationHandler::class)]`, autoconfigured, the generic engine as the inner. The design point, stated firmly: per-type customization should normally compose through the SPIs (higher-priority provider/persister) or serializer/hydrator overrides; **decorate the handler only for cross-cutting behaviour**. `ServerFactory` resolves the handler by service id, so the decorator is picked up transparently.
- The compile-time guards recap for this surface (unregistered/wrong-type override) and where they're thrown.

*Capabilities:* Per-resource serializer/hydrator override (+ the registered-service requirement + guard), standalone registration consequences for I/O, `uriType` segment + Location/link behaviour, handler decoration via `#[AsDecorator]` + the "compose through SPIs first" guidance.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/serializers.md` (the `SerializerInterface` contract + `PolymorphicSerializer`), `https://github.com/haddowg/json-api/blob/main/docs/hydrators.md` (the `HydratorInterface` contract + bases), `https://github.com/haddowg/json-api/blob/main/docs/capability-composition.md`, `https://github.com/haddowg/json-api/blob/main/docs/operations.md` (`OperationHandlerInterface`).

**Bundle-specific additions:** The override **attribute arguments** + the registered-service requirement + `ResourceLocatorPass` validation, the `uriType` route/Location/link consequences, and handler decoration via Symfony's `#[AsDecorator]` are bundle. Core owns the serializer/hydrator contracts and `OperationHandlerInterface`.

---

**`multi-server-and-testing.md`** — Multi-server (config-declared, shipped) & functional testing

*Role:* Two cross-cutting topics: the shipped multi-server feature end-to-end (ADR 0034: config → `server:` assignment → per-server imports → resolution by `_jsonapi_server`), and the `KernelTestCase` functional-testing harness an integrating app copies. Audience: anyone exposing one API as several servers (versioning, an admin surface, a public/internal split) — the single-API reader needs none of the multi-server half — and anyone testing their integration.

*Outline (progressive disclosure):*
- The one-server baseline first: top-level `base_uri`/`version` define the implicit **`default`** server, so a single-API app declares no `servers:` block, imports `$routes->import('.', 'jsonapi')`, and is done. Multi-server is purely additive on top.
- The four moving parts, end-to-end (ADR 0034): **(1) declare** the extra server in `json_api.servers` (its `base_uri`/`version`, inheriting top-level — → configuration.md). **(2) assign** types with `#[AsJsonApiResource(server: 'admin')]` (or `server: ['default', 'admin']` to expose the same type on both; unset = `default` — → resources.md); a standalone serializer/hydrator/relations capability carries the same `server` argument. **(3) mount** each server's routes with a per-server import (`resource: admin`, `type: jsonapi`, with `prefix('/admin')`/`host()` in routes.yaml — → routing.md). **(4) resolution** is automatic: each route's `_jsonapi_server` default drives `ServerProvider::get($name)`, which returns that server's `Server` from a name→factory locator (an unknown name is a `LogicException` — a wiring fault, never a request error).
- What `ServerFactory` builds, **per declared server** (id `haddowg.json_api.server_factory.<name>`): the immutable, memoized core `Server` from *that server's* `base_uri`/`version`, the PSR-17 factories, **only the resources/standalone pairs assigned to that server**, and the `CrudOperationHandler` — deliberately **not** core's PSR-15 middleware chain, only `withHandler()`. A type on two servers is held by both `Server`s with each server's own `base_uri` (so its self-links differ per server).
- The route-name namespacing recap (owned by routing.md): `default` unprefixed `jsonapi.{type}.{action}`, named `jsonapi.{server}.{type}.{action}` — so a shared type never collides.
- The kernel listeners are **unchanged** — they already resolve the server by name; multi-server fell out of the existing `_jsonapi_server` seam with no lifecycle change (→ lifecycle.md).
- The functional-testing harness: `JsonApiFunctionalTestCase extends KernelTestCase` — `getKernelClass()`, `handle(path, method, body)` (sets the vnd.api+json Accept/Content-Type, calls `kernel->handle` with `catch: true` so errors route through `kernel.exception`), `decode(response): array`, and an `afterBoot()` hook for data-layer setup (Doctrine schema create + seed). It snapshots/restores the global error/exception-handler stack so PHPUnit strict mode stays balanced.
- Building a test app: a `MicroKernelTrait` kernel registering FrameworkBundle + JsonApiBundle (+ DoctrineBundle/Foundry for the Doctrine path), autowire + autoconfigure, set resources/serializers/providers/persisters, and `$routes->import('.', 'jsonapi')`. This is the model an integrating app copies for its own functional tests.
- The dual-provider conformance discipline (guidance for integrators): storage-touching behaviour is asserted against **both** providers via an abstract `*ConformanceTestCase` + two thin kernel-naming subclasses (a failure on one localises to that persister); storage-orthogonal concerns (routing/registration/rendering) are witnessed on a single in-memory kernel. The heuristic for which bucket a concern falls in. Tests are spec-grouped (`#[Group('spec:…')]`).

*Capabilities:* The config-declared multi-server feature end-to-end (the implicit `default` + named servers, per-server `server:` assignment, per-server imports, `ServerProvider` resolution by `_jsonapi_server`, ADR 0034), the per-server `ServerFactory`/`ServerProvider` build (no PSR-15 chain), `JsonApiFunctionalTestCase` (handle/decode/afterBoot + handler-stack restore), the MicroKernel test-app pattern, the dual-provider-vs-single-kernel discipline.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/server.md` (the core `Server` value object each `ServerFactory` builds), `https://github.com/haddowg/json-api/blob/main/docs/architecture.md` (the multi-`Server` concept the bundle config-drives), `https://github.com/haddowg/json-api/blob/main/docs/testing.md` (core's runtime testing helpers — `JsonApiDocument`/`JsonApiErrors` — usable inside a bundle `KernelTestCase`).

**Bundle-specific additions:** `ServerProvider`/`ServerFactory`, the `json_api.servers` config map + per-server assignment/import/resolution (ADR 0034), `JsonApiFunctionalTestCase`, the MicroKernel pattern, and the dual-provider discipline are all bundle. Core owns the `Server` value object and the document-assertion helpers.

---

**`security.md`** — Security & deployment posture

*Role:* The bundle-side security and deployment surface: where authentication/authorization sits relative to JSON:API routes, what debug output is gated, and the guarantees the bundle does and does not make. Audience: anyone deploying the bundle to production. A short page; links core `security.md` for the spec-level posture.

*Outline (progressive disclosure):*
- The headline: the bundle adds **no** authentication or authorization of its own — JSON:API routes are ordinary Symfony routes, so the **firewall/authenticators are placed in `security.yaml` exactly as for any route** (per path/host pattern). Because the routes are literal (router-native, no catch-all — → routing.md), `access_control` and per-route firewalls match them normally; multi-server prefixes (`/admin`) make per-server access rules natural (→ multi-server-and-testing.md).
- The firewall ↔ error interplay (recap from errors.md, cross-linked): a security exception on a JSON:API route still renders as a **spec-compliant JSON:API error document** because the route-scoped `ExceptionListener` maps `HttpExceptionInterface` 401/403 — so authentication failures are JSON:API, not HTML login redirects (configure the firewall `entry_point`/`access_denied_handler` accordingly).
- **Debug-meta gating**, stated as a production checklist item: the `ExceptionListener` `$debug` flag is bound from **`%kernel.debug%`** — with debug **off** (prod), `{exception,file,line,trace}` meta and error `detail` are redacted so stack traces and internals never leak; with debug **on** they render. The single rule: ship prod with `APP_ENV=prod` / `kernel.debug=false`.
- **Request body-size limits**: the bundle does **not** impose a body-size cap — the JSON body is read and `json_decode`d as core negotiates. Cap request size at the edge (web server / reverse proxy / Symfony `post_max_size`-equivalent) so a hostile oversized body can't exhaust memory; the opis structural linter (when enabled) is *not* a size guard.
- What the bundle **does** guarantee vs **does not**: it guarantees route-scoping (it never hijacks non-JSON:API routes — `ROUTE_MARKER`), server-side-only filter/sort columns (never client-supplied, parameter-bound — → doctrine.md), and debug redaction in prod. It does **not** provide auth, rate-limiting, body-size limits, CORS, or CSRF — all standard Symfony/edge concerns the integrator owns.
- The spec-level security posture (PII in errors, the JSON:API security considerations) is **core's** — link core `security.md`.

*Capabilities:* Firewall/authenticator placement relative to JSON:API routes, the firewall↔JSON:API-error interplay, `%kernel.debug%`-gated debug meta/detail, the no-body-size-limit stance + edge-capping guidance, the bundle does/doesn't-guarantee ledger.

**Links to core:** `https://github.com/haddowg/json-api/blob/main/docs/security.md` (the spec-level security posture the bundle inherits), `https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md` (the error model the firewall interplay rides on).

**Bundle-specific additions:** The firewall-placement guidance, the route-scoped firewall↔error interplay, the `%kernel.debug%` gating wiring, the body-size stance, and the does/doesn't-guarantee ledger are bundle. Core owns the spec-level security considerations.

---

## 3. Example-app spec — `examples/music-catalog-symfony/`

A complete JSON:API 1.1 service built on the bundle, served from a real **Symfony + Doctrine** app over an in-memory SQLite database — **full parity** with core's `examples/music-catalog/`, same eight domains, same theme, so a reader can hold the two example apps side by side and see exactly what the framework integration adds. It is the **single source of truth** for the bundle docs: every snippet is extracted from a CI-run `KernelTestCase`, so the docs cannot drift.

### How it reworks the existing Doctrine test infra

The app is built **partly by reworking `tests/Functional/App/Doctrine/`** — today an article/author/comment/tag/vault domain (`ArticleEntity`, `AuthorEntity`, `CommentEntity`, `TagEntity`, `VaultEntity`; `DoctrineArticleResource` et al.; `DoctrineJsonApiTestKernel`; `OverridingArticleProvider`, `AboveFallbackArticleProvider`, `GuideOnlyArticlesExtension`) that already proves the *real* bundle APIs against a live SQLite database — and **partly net-new** where no Doctrine infrastructure exists today (the Doctrine polymorphic provider, and the Doctrine `MorphTo`-to-one read). The genuine reworks **re-theme proven infra to the music catalog and complete the domain to all eight entities**, preserving the seams the article infra already exercises:
- **(rework)** `DoctrineArticleResource`'s `#[AsJsonApiResource(entity: …)]` + `UniqueEntity('title')` post-hydration rule → becomes `AlbumResource`/`UserResource` entity mappings with a `UniqueEntity` on, e.g., `users.email` (the `UniqueEntity` runtime needs `symfony/doctrine-bridge` — → validation.md).
- **(rework)** `GuideOnlyArticlesExtension` (a `category = 'guide'` scope) → becomes a `PublishedAlbumsExtension` (a `published = true` base scope) — the **demonstrated query-extension seam (1)**.
- **(rework)** `OverridingArticleProvider`/`AboveFallbackArticleProvider` (the priority/shadow witnesses) → become the **priority-shadow witness** of the custom provider (seam 2): the `-128` Doctrine fallback shadowed by a default-priority provider, proven by a `CustomProviderTest`.
- **(rework)** `ArticleEntity`'s ManyToOne/OneToMany/ManyToMany topology → maps onto the music relations (album→artist, album→tracks, track↔playlists, favorite→favoritable, library→items).
- **(NET-NEW)** `LibraryItemsProvider` — the polymorphic `MorphToMany` provider (seam 2's polymorphic half). **No Doctrine-backed polymorphic infrastructure exists today**: the only Doctrine polymorphic test today asserts the provider *throws*, and the in-memory polymorphic app is not Doctrine-backed. This is genuinely new code, not a rework of `OverridingArticleProvider` (which only proved priority shadowing). Sub-spec: it resolves a `MorphToMany`'s **mixed members across per-type repositories** (tracks/albums/artists), **shares the `EntityManager`** (so a fetched row stays *managed* and is writable on the same kernel), and **reuses `CriteriaApplier` + an `OffsetWindow`** to stay spec-conformant (filter/sort/page where a shared vocabulary exists). Proven by `CustomProviderTest`/`PolymorphicTest`.
- **(NET-NEW coverage)** the Doctrine **`MorphTo` to-one read** (seam 3): a polymorphic *to-one* served by the Doctrine provider is supported by core/the bundle but **has no Doctrine functional witness today** — the example's `PolymorphicTest` is its **first Doctrine witness**, not a rework.
- **(rework)** `DoctrineJsonApiTestKernel` (FrameworkBundle + DoctrineBundle + Foundry + JsonApiBundle over `:memory:` sqlite, schema in `afterBoot`) → becomes the example app's `MusicCatalogKernel`, but a real app kernel (a `bundles.php`, real `config/`) rather than a `MicroKernelTrait` test kernel.

### Domain — 8 domains = 7 Doctrine entities (`src/Entity/`) + 1 store-backed `Chart`

The eight domains split cleanly: **7 are Doctrine-entity-backed `AbstractResource` types** (Artist, Album, Track, Playlist, User, Favorite, Library) and **1 is a store-backed serialize-only `Chart`** (no entity, no resource). The table below lists all eight, with `Chart` marked "no entity"; the resources list further down enumerates exactly the **7** entity-backed resources, so entity-count (7) + Chart and resource-count (7) reconcile.

| Entity | Notable mapping | Relations |
| --- | --- | --- |
| `Artist` | id, name, slug, bio (nullable), createdAt | OneToMany `albums` |
| `Album` | id, title, averageRating (nullable), releasedAt, `published` (bool, scoped by the extension), `releaseInfo` (JSON column ↔ `Map`) | ManyToOne `artist`, OneToMany `tracks` |
| `Track` | id, title, trackNumber, `length_seconds` (column renamed from `durationSeconds`), explicit, genres (JSON ↔ `ArrayList`) | ManyToOne `album`, ManyToMany `playlists` (pivot) |
| `Playlist` | id (client-generated uuid), title, slug, public, externalId (nullable) | ManyToOne `owner`→User, ManyToMany `tracks` |
| `User` | id, email (`UniqueEntity`), displayName, birthDate (nullable), preferences (JSON ↔ `ArrayHash`), lastSeenIp (nullable) | OneToMany `playlists`, OneToOne `library` |
| `Favorite` | id, favoritedAt | ManyToOne `user`; **`MorphTo favoritable`** (a `targetType`+`targetId` pair) → Track\|Album\|Artist — **demonstrated seam (3)** |
| `Library` | id | ManyToOne `owner`→User; **`MorphToMany items`** → Track\|Album\|Artist — powered by the custom provider, **seam (2)** |
| `Chart` | **no entity** — store-backed, serialize-only (id, name, period, entries live in a store, not a Doctrine entity) | — |

### Resources & services (`src/Resource/`, `src/Serializer/`, etc.)

- The **7** entity-backed `AbstractResource` subclasses as services (one per Doctrine entity, reconciling with the 7 entities above): `ArtistResource`, `AlbumResource`, `TrackResource`, `PlaylistResource`, `UserResource`, `FavoriteResource`, `LibraryResource`, each with `#[AsJsonApiResource(entity: …)]`. They reuse the core example's field/relation declarations (the field DSL is core) so the two apps stay snippet-parallel. `Chart` is the 8th domain but has **no resource** (a standalone serializer instead, below).
- `ChartResource`-less type: a hand-written `ChartSerializer` registered with `#[AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchCollection, Operation::FetchOne])]` — the **standalone serialize-plus-fetch witness** (no entity, no hydrator, read-only). Its data comes from a small custom `DataProvider` (no Doctrine entity), proving a resource-less fetchable type.
- **Reference-data witness** — a `countries` type with no entity, the *simplest* custom-provider + serializer pairing: a standalone `CountrySerializer` (`#[AsJsonApiSerializer(type: 'countries', operations: [Operation::FetchCollection, Operation::FetchOne])]`) + a static `CountryProvider implements DataProviderInterface` sourcing its rows from `symfony/intl`'s `Countries` (id = ISO code, attribute = localized name). Read-only, no Doctrine — and it still serves **filter/sort/pagination over the non-DB list by reusing `CriteriaApplier`**, proving an external/static source is a first-class collection. The docs note a backed `Genre` enum (`genres`, id = case value) as the even-simpler fixed-data variant.
- A custom `TrackSerializer` and `PlaylistHydrator` registered via `#[AsJsonApiResource(serializer: …, hydrator: …)]` — the per-resource override witnesses (each with a bound constructor arg, proving DI resolution).
- **No `uriType` witness:** core's example domain does not override `uriType`, so the bundle example app keeps types and URL segments identical — `uriType` is documented in prose only (custom-serializers-hydrators.md), never witnessed here.
- **Minimal multi-server witness (ADR 0034):** a second `admin` server (declared in `json_api.yaml`, mounted under `/admin`) re-exposes one or two resources via the `server:` argument — at least one **shared across both servers**: `#[AsJsonApiResource(server: ['default', 'admin'], …)]` on `AlbumResource` (so `/albums` and `/admin/albums` both resolve, each with its own `base_uri`), plus an `admin`-only resource (e.g. `UserResource` with `server: 'admin'`). Kept deliberately small so it doesn't bloat the 8-domain core; proven by `MultiServerTest`.

### The three demonstrated bundle seams (in code, not prose)

1. **A Doctrine query extension (rework)** — `PublishedAlbumsExtension implements DoctrineExtensionInterface` scoping `albums` to `published = true` (applied unconditionally, recording `QueryPurpose` per the fail-closed contract). Reworked from `GuideOnlyArticlesExtension`. Proven: a client filter ANDs on top, the COUNT respects the scope, an unpublished album is a 404 on `GET /albums/{id}`.
2. **A custom `DataProvider` powering a polymorphic `MorphToMany` (NET-NEW) over a priority-shadow witness (rework)** — two distinct things sharing the seam: (a) the **priority shadow** — a default-priority provider shadowing the `-128` Doctrine fallback — is *reworked* from `OverridingArticleProvider`/`AboveFallbackArticleProvider`; (b) `LibraryItemsProvider implements DataProviderInterface`, supporting `libraries`' `items` (a `MorphToMany` the Doctrine provider **throws** on because members span entity classes), is **NET-NEW** — no Doctrine-backed polymorphic provider exists today. It resolves the mixed members (tracks/albums/artists) **across their per-type repositories**, **shares the `EntityManager`** (a fetched row stays managed for writes), and **reuses `CriteriaApplier` + an `OffsetWindow`** to stay spec-conformant. Proven: `GET /libraries/{id}/items` returns a mixed collection the Doctrine provider could not.
3. **The `MorphTo` polymorphic to-one (NET Doctrine coverage)** — `FavoriteResource`'s `favoritable` relation (`MorphTo` over Track\|Album\|Artist), served by the Doctrine provider (a polymorphic *to-one* is supported; only the polymorphic *to-many* throws). This is the **first Doctrine functional witness** of a polymorphic to-one read (no existing Doctrine test covers it). Proven: `GET /favorites/{id}/favoritable` resolves the serializer from the actual related object; an empty target → `data: null`.

A **custom `ConstraintTranslator`** is **prose-only** in `validation.md` (documented as the extension-point recipe), **not** in the example app — per the maintainer's ratified constraint.

### Config (`config/`)

- `config/bundles.php` registering FrameworkBundle, DoctrineBundle, JsonApiBundle (and `symfony/validator` services discovered automatically). The app's `composer.json` depends on `symfony/doctrine-bridge` (brought transitively by `doctrine/doctrine-bundle`) so `UniqueEntity` resolves at runtime.
- `config/packages/json_api.yaml`: `base_uri`, `version`, a `schema_validation: true` variant exercised by `SchemaValidationTest`, and a **second `admin` server** under `json_api.servers` (its own `base_uri`, inheriting `version`) — the minimal multi-server witness (ADR 0034).
- `config/routes/json_api.yaml`: the `default` import (`resource: '.' type: jsonapi`) **plus** a per-server `admin` import (`resource: admin type: jsonapi` under `prefix: /admin`) mounting the `admin` server's routes.
- `config/packages/doctrine.yaml`: the `:memory:` sqlite connection + attribute mapping.

### File tree

```
examples/music-catalog-symfony/
├── README.md
├── composer.json                 # depends on the bundle + symfony/* (incl. symfony/doctrine-bridge for UniqueEntity, symfony/intl for the countries resource) + doctrine/*; its own test entry
├── config/
│   ├── bundles.php
│   ├── packages/json_api.yaml     # default + a named `admin` server (multi-server witness, ADR 0034)
│   ├── packages/doctrine.yaml
│   └── routes/json_api.yaml       # the `.` default import + a per-server `admin` import under /admin
├── src/
│   ├── Entity/                    # Artist Album Track Playlist User Favorite Library (+ Chart is store-backed, no entity)
│   ├── Resource/                  # the 7 AbstractResource services
│   ├── Serializer/               # ChartSerializer (standalone), CountrySerializer (reference data), TrackSerializer (override)
│   ├── Hydrator/                 # PlaylistHydrator (override)
│   ├── Provider/                 # LibraryItemsProvider (seam 2), ChartProvider (resource-less fetch), CountryProvider (symfony/intl static source)
│   ├── Query/                    # PublishedAlbumsExtension (seam 1)
│   └── DataFixtures/             # Foundry factories / a deterministic seed
├── tests/
│   ├── MusicCatalogKernelTestCase.php   # extends JsonApiFunctionalTestCase; afterBoot = schema + seed
│   ├── GettingStartedTest.php
│   ├── ReadQueryTest.php
│   ├── WriteTest.php
│   ├── ValidationTest.php
│   ├── RelationshipReadTest.php
│   ├── RelationshipMutationTest.php
│   ├── RelatedCollectionTest.php
│   ├── PolymorphicTest.php
│   ├── DoctrineExtensionTest.php
│   ├── CustomProviderTest.php
│   ├── CapabilityCompositionTest.php
│   ├── CustomSerializerHydratorTest.php
│   ├── MultiServerTest.php           # the admin server: per-server reachability + distinct base_uri (ADR 0034)
│   ├── ErrorHandlingTest.php
│   └── SchemaValidationTest.php
└── phpunit.xml.dist
```

### Test suites — what each proves (CI-wired)

| Test | Proves | Backs page |
| --- | --- | --- |
| `GettingStartedTest` | 200 collection / 201+Location / 404 over Doctrine | getting-started.md |
| `ReadQueryTest` | filter/sort/pagination + sparse fieldsets + include over the Doctrine provider | data-layer.md, doctrine.md |
| `WriteTest` | create/update/delete; the relationship-strip + single-flush write flow | data-layer.md |
| `ValidationTest` | 422 + pointers; create-vs-update Context; `Map` cascade; `UniqueEntity` | validation.md |
| `RelationshipReadTest` | related + relationship endpoints; convention links; empty to-one `data:null` | relationships.md |
| `RelationshipMutationTest` | PATCH/POST/DELETE linkage via `mutateRelationship`; 403 gates | relationships.md |
| `RelatedCollectionTest` | paginated related to-many (FK fast-path + `IN`-subquery) | relationships.md, doctrine.md |
| `PolymorphicTest` | `MorphTo` to-one over Doctrine (seam 3, the **first** Doctrine witness); `MorphToMany` via the NET-NEW `LibraryItemsProvider` (seam 2 polymorphic half) | relationships.md |
| `DoctrineExtensionTest` | the published-only scope ANDs on top; out-of-scope → 404 (seam 1) | custom-data-providers.md, doctrine.md |
| `CustomProviderTest` | the default-priority provider shadows the -128 Doctrine fallback (seam 2 priority-shadow, reworked) | custom-data-providers.md, data-layer.md |
| `ReferenceDataTest` | a `symfony/intl`-sourced `countries` resource: static custom provider + standalone serializer, read-only, filter/sort/paginate over a non-DB list via `CriteriaApplier` | custom-data-providers.md, capability-composition.md |
| `CapabilityCompositionTest` | the standalone `charts` serialize-plus-fetch type; default-operations asymmetry | capability-composition.md |
| `CustomSerializerHydratorTest` | per-resource serializer/hydrator override with DI (no `uriType` witness — prose-only) | custom-serializers-hydrators.md |
| `MultiServerTest` | the `admin` server: a shared resource reachable on both `default` and `admin`, distinct per-server `base_uri`, an `admin`-only resource 404 on `default` (ADR 0034) | multi-server-and-testing.md |
| `ErrorHandlingTest` | route-scoped rendering; 401/403/404/500 as JSON:API; debug gating | errors.md |
| `SchemaValidationTest` | the opis structural linter → 400, distinct from the 422 bridge | validation.md, configuration.md |

---

## 4. Capability → page matrix

Every capability from the feature-surface audit, mapped to its owning page. (Concern A = Install/DI; B = Routing/lifecycle/errors; C = Data layer; D = Validation; E = Capability/multi-server/testing.)

| Audited capability | Owning page |
| --- | --- |
| Installation & bundle registration (`dev-main` dance) | install.md |
| Route registration (the required `import('.', 'jsonapi')` step) | routing.md (signposted from install.md, getting-started.md) |
| Bundle configuration (`json_api:` tree) | configuration.md |
| Resource discovery & `#[AsJsonApiResource]` | resources.md |
| Standalone capability attributes (`#[AsJsonApiSerializer/Hydrator/Relations]`) | capability-composition.md |
| `Operation` enum & per-type endpoint allow-list | routing.md (model also in capability-composition.md) |
| Tagged SPI/extension interfaces (autoconfigured tags + priority) | data-layer.md (provider/persister), custom-data-providers.md (extension), validation.md (translator) |
| Compiler passes & type→entity map (`ResourceLocatorPass`, `DoctrineEntityMapPass`) | resources.md (resource/standalone collection + guards), doctrine.md (entity map) |
| `schema_validation` toggle & optional-dependency gating | configuration.md (matrix) + validation.md (the linter) |
| Registering the route loader (zero-config endpoints) | routing.md |
| Operation-gated routes | routing.md |
| Relationship & related-resource routes | routing.md |
| Route defaults & `TargetResolver` (explicit-route seam) | routing.md |
| The kernel-listener lifecycle | lifecycle.md |
| Content negotiation, query-param & write-body validation | lifecycle.md (call sites; rules → core) |
| Optional opis structural validation toggle | validation.md (+ configuration.md for the flag) |
| Route-scoped error handling (`ExceptionListener`) | errors.md |
| Server resolution (`ServerProvider`/`ServerFactory`, implicit `default` + named servers) | multi-server-and-testing.md |
| `DataProviderInterface` (read SPI) | data-layer.md |
| `DataPersisterInterface` (write SPI) | data-layer.md |
| Provider/persister resolution (priority + first-match, -128) | data-layer.md (+ recipe in custom-data-providers.md) |
| `CollectionCriteria` + `CriteriaApplier` | data-layer.md |
| Doctrine reference adapter (provider + persister) | doctrine.md |
| Doctrine filter/sort handlers | doctrine.md |
| Doctrine related-collection scoping + polymorphic boundary | doctrine.md (+ relationships.md for the endpoint) |
| `DoctrineExtensionInterface` (query-scoping seam) | custom-data-providers.md |
| In-memory provider/persister/store (worked example) | custom-data-providers.md |
| `CrudOperationHandler` (the generic engine) | data-layer.md |
| Validator bridge wiring & gating | validation.md |
| Document-first pass (Required/Nullable/Context, allowExtraFields) | validation.md |
| 422 rendering (`ValidationFailed` + `JsonPointerBuilder`) | validation.md |
| Constraint translation table | validation.md |
| `When`/date-bounds via `Callback` | validation.md |
| `CompareField` (document-level cross-field) | validation.md |
| Entity-level pass (`EntityConstraintInterface` + `UniqueEntity`) | validation.md |
| `ConstraintTranslatorInterface` extension point | validation.md (prose-only) |
| Nested `Map` child-constraint cascade | validation.md |
| `EmailFormat` strict-mode degradation | validation.md |
| `AbstractResource` baseline + capability decomposition | resources.md (on-ramp) + capability-composition.md (decomposition) |
| Per-resource serializer/hydrator override | custom-serializers-hydrators.md |
| Standalone serializer/hydrator capability | capability-composition.md |
| Standalone relations (`#[AsJsonApiRelations]` + `RelationsRegistry`) | relationships.md + capability-composition.md (wiring rationale) |
| Per-relation endpoint exposure (handler-enforced 404/403) | relationships.md |
| Handler override via service decoration | custom-serializers-hydrators.md |
| `uriType` (URL segment distinct from type) | custom-serializers-hydrators.md (canonical; forward-linked from resources.md, routing.md) |
| `TypeMetadataResolver` (tolerating a bare pair) | data-layer.md |
| Multi-server (config-declared servers, per-server assignment/routing, ADR 0034) | multi-server-and-testing.md (config → configuration.md, assignment → resources.md, routing → routing.md) |
| Security & deployment posture (firewall placement, debug gating, body-size) | security.md |
| Functional testing harness (`JsonApiFunctionalTestCase`) | multi-server-and-testing.md |
| Dual-provider conformance pattern | multi-server-and-testing.md |

---

## 5. Completeness audit

### Every audited capability is placed

All capabilities across the four audit concerns map to a page above (Section 4). The dense ones — the SPI (`data-layer.md`), the Doctrine adapter (`doctrine.md`), the validator bridge (`validation.md`), and capability composition (split between `capability-composition.md`, `routing.md`, and `custom-serializers-hydrators.md`) — each own a coherent slice with no orphans.

### Every one of the 34 bundle ADRs is placed

| ADR | Owning page |
| --- | --- |
| 0001 lifecycle as kernel listeners | lifecycle.md |
| 0002 routing via Target resolver + auto-routes | routing.md |
| 0003 routes render all errors as documents | errors.md |
| 0004 Provider/Persister SPI, Doctrine reference | data-layer.md |
| 0005 entity mapping on the resource attribute | doctrine.md (+ resources.md intro) |
| 0006 criteria-driven collection fetches | data-layer.md |
| 0007 priority-ordered first-match resolution | data-layer.md / custom-data-providers.md |
| 0008 Doctrine query-customization extensions | custom-data-providers.md |
| 0009 filter defaults folded in the applier | data-layer.md |
| 0010 writes through a DataPersister SPI | data-layer.md |
| 0011 writes dispatch through the single CRUD handler | data-layer.md |
| 0012 Symfony Validator bridge | validation.md |
| 0013 opis schema validation linter | validation.md (+ configuration.md) |
| 0014 entity-level post-hydration validation | validation.md |
| 0015 relationship load-state predicate | doctrine.md (+ relationships.md cross-ref) |
| 0016 related/relationship read endpoints | relationships.md |
| 0017 relationship mutations via persister seam | relationships.md |
| 0018 whole-resource relationship hydration reuses the seam | data-layer.md (+ relationships.md) |
| 0019 relationship-existence filters → EXISTS subqueries | doctrine.md |
| 0020 nested-attribute child-constraint cascade | validation.md |
| 0021 the generic CRUD engine is the zero-handler default | data-layer.md |
| 0022 `uriType` segment | custom-serializers-hydrators.md (+ routing.md, resources.md) |
| 0023 per-resource serializer/hydrator override | custom-serializers-hydrators.md |
| 0024 standalone serializer/hydrator capability | capability-composition.md |
| 0025 per-type operation exposure + operation-gated routing | routing.md |
| 0026 standalone relations declaration | relationships.md / capability-composition.md |
| 0027 per-relation endpoint exposure | relationships.md |
| 0028 handler override via service decoration | custom-serializers-hydrators.md |
| 0029 Doctrine constructor-less instantiation | doctrine.md |
| 0030 queryable/paginated related collections | relationships.md (+ doctrine.md push-down) |
| 0031 Doctrine related-collection arity branch (FK vs IN) | doctrine.md |
| 0032 polymorphic related endpoints | relationships.md (+ doctrine.md / custom-data-providers.md boundary) |
| 0033 singular-filter collapse in the CRUD handler | data-layer.md (cross-ref to core's read/query semantics) |
| 0034 multi-server config-declared + per-server assignment/routing | multi-server-and-testing.md (+ configuration.md servers map, resources.md `server:` assignment, routing.md per-server import/names) |

### Deliberately deferred / out of scope

- **Core concepts are not re-documented.** The field DSL, relation DSL, constraint vocabulary, response VOs, document model, content-negotiation rules, the exception catalogue, profiles, links/meta, and the core spec-compliance ledger are **linked to core**, never duplicated — per the cardinal rule. Bundle pages extend a core snippet only where the Symfony usage differs (e.g. a resource declared as a service) or a bundle-only feature needs showing.
- **The custom `ConstraintTranslator`** is documented as prose in `validation.md` but **deliberately not built into the example app** (per the ratified constraint) — the in-code seams are the query extension, the NET-NEW custom provider powering the polymorphic `MorphToMany`, the `MorphTo` to-one (first Doctrine witness), and the minimal multi-server `admin` server.
- **Internal compiler-pass mechanics** (`ResourceLocatorPass`/`DoctrineEntityMapPass` internals beyond the user-facing build-time error messages) are surfaced only as the `LogicException` messages a reader will hit, not as an API.
- **Multi-server is documented as the shipped feature** (ADR 0034) — config-declared servers, per-server `server:` assignment, per-server route imports, `ServerProvider` resolution — across configuration.md / resources.md / routing.md / multi-server-and-testing.md, with a minimal `admin`-server witness in the example app. (The earlier "single-default / dead stub / not-yet-functional" framing is retired.)
- **Profiles and the spec-compliance ledger** as standalone bundle pages are deferred: the bundle adds nothing to core's profile model or spec ledger beyond what the lifecycle/error pages cover, so they link core rather than getting bundle pages. **Security/deployment posture now has a dedicated bundle page** (`security.md`) for the bundle-side concerns (firewall placement, debug gating, body-size), linking core `security.md` for the spec-level posture.

### Open items flagged during the audit (for the maintainer)

- `symfony/doctrine-bridge` is a runtime requirement for `UniqueEntity` but is absent from the bundle's `composer.json` `suggest` (present transitively) — **add it to `suggest`** (the docs already state the requirement explicitly: validation.md at the witness, configuration.md in the matrix, and the example app's `composer.json` depends on it).
