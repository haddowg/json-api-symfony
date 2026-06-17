# CLAUDE.md — executor playbook (json-api-symfony)

Maintenance playbook for `haddowg/json-api-symfony`, read by future Claude Code
sessions (including after compaction or restart). It records the decisions behind
this bundle and the conventions it inherits.

## Project orientation

`haddowg/json-api-symfony` is a **Symfony bundle** that makes
[`haddowg/json-api`](https://github.com/haddowg/json-api) idiomatic in a Symfony
application. The core library is **framework- and storage-agnostic**; this bundle
supplies the framework integration (DI, routing, the request lifecycle) and a
**reference Doctrine ORM data layer**.

- **The core lives as a sibling checkout** at `../json-api`
  (`/Users/gregory.haddow/Sites/json-api`). The bundle requires it as
  `haddowg/json-api: dev-main` with **no repository in `composer.json`**. Local
  dev resolves it through a **global** Composer path repository
  (`composer config -g repositories.haddowg-json-api '{"type":"path","url":"/abs/path/to/json-api","options":{"symlink":true}}'`)
  that symlinks the sibling checkout — so **keep core on its `main` branch**, since
  a path repo reports `dev-<branch>` and only `dev-main` satisfies the constraint
  (a committed project repo would shadow the global one, so there deliberately
  isn't one). CI injects a **global VCS repository** instead, resolving `dev-main`
  from GitHub with no extra checkout. Pin `^1.0` once core ships to Packagist. The
  core repo's own `CLAUDE.md` is the authority on the library's internals.
- This bundle and core are **co-evolving pre-1.0**: building the bundle is a
  forcing function to validate the core has everything required for a proper
  integration use case before core's 1.0 freezes its API. When the bundle hits
  friction, prefer **fixing core** (it's still cheap to change) over working
  around it here. Each core change lands in the core repo (PR + ADR + tag); the
  bundle then depends on that exact tag.

## Architecture decisions (the design this bundle implements)

- **DI / discovery.** Resources are Symfony services, discovered by
  autoconfiguration (any `AbstractResource`) plus an optional
  `#[AsJsonApiResource]` attribute for metadata (server assignment, overrides).
  Core's `ResourceRegistry` gains a **lazy container resolver** (reads `::$type`
  statically, constructs via an injected resolver on first use) so registered
  services can have real constructor dependencies.
- **Routing.** A route loader auto-registers the standard JSON:API endpoint set
  per resource; a `Target` resolver builds the `Operation\Target` from route
  defaults. Explicit-route users use the resolver primitive directly. Router-native
  (no catch-all path parsing).
- **Lifecycle = kernel listeners (NOT core's PSR-15 middleware).**
  `kernel.request` negotiates + parses + resolves the operation and calls
  **`Server::dispatch()`** (core's PSR-15-bypassing entry point); `kernel.view`
  renders the response value object to HttpFoundation; `kernel.exception` renders
  errors. This requires core's lifecycle *logic* (negotiation, validation,
  rendering) be drivable **without instantiating any `Middleware\*` class** — an
  ongoing core-extraction obligation.
- **Errors.** The `kernel.exception` listener is **route-scoped** (acts only when
  the matched route carries the bundle's marker) and **owns all errors** on
  JSON:API routes: core exceptions natively, `HttpExceptionInterface` mapped by
  status (firewall 401/403, routing 404), everything else 500. Debug meta gated on
  `kernel.debug`; unexpected errors logged via Symfony's logger.
- **Multi-server / versioning.** Architecture is **N-server-capable, single-server
  optimized**: one API needs no `servers:` block (implicit `default` server);
  server selection is a `_jsonapi_server` route default.
- **Validation.** First-class **Symfony Validator bridge**: translate core
  `ConstraintInterface` VOs → Symfony `Constraint`s, validate on create/update in
  the hydration path, map violations → JSON:API `422` with `source.pointer`.
  `When` and the date bounds (`After`/`Before`/`Between`) via `Callback`; a custom
  constraint VO (attached with core's `constrain()`) via a class-keyed
  `ConstraintTranslatorInterface` extension point. Core's
  opis JSON-Schema validation is wired as an optional dev/CI toggle.
- **Data layer = Provider/Persister SPI, Doctrine reference impl.** The generic
  CRUD handler is storage-agnostic over a bundle `DataProvider` (fetch one /
  collection) + `DataPersister` (create / update / delete / relationship-mutate),
  resolved per type. Doctrine implements them, composing core's
  `FilterHandlerInterface` / `SortHandlerInterface`. An **in-memory provider** is
  kept as a test double + conformance witness so findings stay attributable.
- **Handler model.** Per-type operation-handler dispatch is the foundation; the
  **generic Doctrine CRUD handler is the capstone**, built as a *refactor* of the
  proven per-type handlers over the SPI (not a speculative greenfield build).

The full decision record and the phase plan live in the **core repo's auto-memory**
at `~/.claude/projects/-Users-gregory-haddow-Sites-json-api/memory/json-api-symfony-bundle-plan.md`.

Record bundle architecture decisions as ADRs under `docs/adr/` — follow
[`docs/adr/ADR-FORMAT.md`](docs/adr/ADR-FORMAT.md) (a short title stating the
decision, then 1–3 sentences of *why*). The ADRs already written: `0001`–`0032`.

## Phases (vertical slices, Doctrine-backed from Phase 1)

> **Current status (2026-06-11): Phases 0–2 merged; Phases 3 (relationships) and
> 4 (the capstone CRUD engine) — plus the two follow-ups, many-to-many related
> collections and polymorphic (`MorphTo`/`MorphToMany`) related endpoints — all
> complete locally on `feat/standalone-serializer-hydrator-capability` (16 commits)
> with the consumed core changes on the sibling's `main` (6 commits ahead of
> `origin/main`); EVERYTHING unsigned + unpushed, pending re-sign and merge** (Phase
> 2 = PRs #6–#8; Phase 1 = #1–#4). The exact commit-to-PR merge plan for this
> unsigned backlog is in the bundle auto-memory
> `[[phase4-and-followups-merge-plan]]`. **Phase 5 (v1 consolidation) is next.**
> Resource writes (`POST /{type}`, `PATCH`/`DELETE` `/{type}/{id}`) run
> end-to-end on **both** providers over a new **`DataPersister` SPI** — the write
> twin of `DataProvider`: per-type first-match resolution with the reference
> Doctrine persister as the `-128` fallback and an in-memory witness sharing one
> `InMemoryStore` with the read provider (so a write is immediately readable);
> entities flow as `object` and the SPI carries `instantiate()` since the
> persister owns the storage mapping (ADR 0010). The read handler grew
> create/update/delete arms and became the single **`CrudOperationHandler`**:
> resolve persister, drive core's per-type hydrator, commit, render `201`+Location
> / `200` / `204` (ADR 0011). A first-class **Symfony Validator bridge**
> translates core's constraint vocabulary (declared metadata core never executes)
> into Symfony rules, validates the document **before hydration**, and renders
> violations as `422` with `source.pointer`; it is **optional** (`symfony/validator`
> is `suggest`), resolves `Required`/`Nullable` by create/update `Context`, and
> exposes a class-keyed `ConstraintTranslatorInterface` extension point for custom
> constraint VOs (ADR 0012). An **optional opis
> structural linter** (`json_api.schema_validation`, off by default) validates
> write bodies against the JSON:API JSON Schema → `400`, separate from the
> semantic `422` bridge (ADR 0013). Built on two core changes — error-document
> status fidelity (a uniform `422` bag no longer collapses to `400`; core #25) and
> a settable response status + `NoContentResponse` for `201`/`204` (core #26),
> both merged to core `main`; the bundle stays on `dev-main` through pre-1.0 (see
> the `core-dependency-stays-on-dev-main-until-v1` bundle auto-memory). Functional
> acceptance: `WriteConformanceTestCase` + `ValidationConformanceTestCase` (+ a
> `SchemaValidationTest`) run identical assertions against the in-memory and
> Doctrine-sqlite kernels. The follow-up has since landed (PRs #10/#11, core
> #27/#28, ADR 0012 updated): `When` and the date bounds (`After`/`Before`/`Between`)
> are now translated via `Callback` — `When` conditionally re-validates the inner
> constraints; the date bounds coerce the value to `\DateTimeImmutable` and compare
> against a bound resolved at validation time, so a closure bound such as "now"
> reflects the request (exercised under a frozen `symfony/clock`). Core gained a
> `when()` field builder, so a conditional rule is both declarable and executable —
> proven declare→execute on both providers. `Timezone` was **removed from core**
> (impractical against wire offsets — an ISO-8601 value carries an offset, not a
> named zone; core ADR 0020). A class-keyed `ConstraintTranslatorInterface` is the
> typed extension point for an app's own constraint VOs (the `$id`-keyed `Custom`
> escape hatch was removed; core ADR 0021). The vocabulary then grew to close most
> of the surfaced gaps: composition combinators `Sequentially`/`AtLeastOneOf`
> (core ADR 0022), the cross-field `CompareField` executed document-level against
> the sibling value (core ADR 0023), and `UniqueEntity` over a reusable
> post-hydration **entity-validation seam** (`EntityConstraintInterface`, bundle
> ADR 0014) — so a duplicate is caught against the repository before commit.
>
> **Phase 3 (relationships) then landed end-to-end on both providers (slices
> S0–S6) — unsigned/unpushed locally pending re-sign.** Resources declare
> relations and render linkage plus self/related `links` by convention (default
> on, `withoutLinks()` opt-out; core ADR 0024), with a `dataOnlyWhenLoaded()`
> policy over a storage-aware **load-state seam** so a lazy Doctrine to-many emits
> links-only without forcing a fetch (core ADR 0025, bundle ADR 0015). The related
> (`GET /{type}/{id}/{rel}`) and relationship (`GET …/relationships/{rel}`) read
> endpoints + compound `?include` run on a public related-value accessor (core ADR
> 0026, bundle ADR 0016); an empty to-one renders `data: null` (core ADR 0027).
> Relationship **mutation** (`PATCH`/`POST`/`DELETE …/relationships/{rel}`) closes
> the headline core gap — `AbstractResource` now implements
> `UpdateRelationshipHydratorInterface`, ships a `Mode` enum + a top-level
> relationship-body parser + `cannotReplace()`/`cannotRemove()` flags, and finally
> **throws** `FullReplacementProhibited`/`RemovalProhibited`/`RelationshipTypeInappropriate`
> (core ADRs 0028–0029); storage-correct apply (id → managed reference / stored
> object) rides a new **`DataPersister::mutateRelationship()`** seam, reused for
> relationships embedded in whole-resource writes (bundle ADRs 0017–0018).
> Relationship-existence filters `WhereHas`/`WhereDoesntHave` execute as in-memory
> predicates (core ADR 0030) and Doctrine `EXISTS`/`NOT EXISTS` subqueries (bundle
> ADR 0019). The last v1 *validation* gap closed **implicitly**: a structured
> `Map` attribute's child constraints now cascade through the validator bridge to
> `422` with nested `/data/attributes/<map>/<child>` pointers — no `Valid` marker
> (bundle ADR 0020, reversible to an explicit marker). Core changes rode their own
> `feat/*` branches (ff'd into core `main`) for later PRs; the bundle stayed linear
> on `main`.
>
> **Phase 4 (the capstone) then landed end-to-end on both providers — a linear run
> of nine commits on `feat/standalone-serializer-hydrator-capability`, unsigned +
> unpushed.** It reframed the capstone from "build a generic engine" to "the engine
> is already generic — consolidate it and make the type model fully
> capability-composed." A JSON:API type is now assembled from **independent,
> optional capabilities** — serializer, hydrator, relations, provider, persister —
> any combination supported, **nothing coupled to `AbstractResource`** (which stays
> pure sugar bundling serializer+hydrator+relations): if you need no read endpoints
> you need no provider; no writes, no hydrator/persister. The wiring runs through a
> `ResourceLocatorPass` collecting resource + serializer + hydrator + relations tags
> and a `TypeMetadataResolver` seam that tolerates a **bare serializer/hydrator
> pair** (no field inventory) without per-type branching (bundle ADR 0021/0024).
> Slices: a resource customizes its **URI segment** distinct from its type
> (`uriType`, ADR 0022); a type **overrides its serializer/hydrator** or registers
> them **standalone** without a resource (ADRs 0023–0024); a per-type **operation
> allow-list** gates which CRUD endpoints exist (resource default = all, standalone
> serializer default = none) and the route loader emits only the allowed routes from
> **plain-scalar route descriptors** (ADR 0025); **relations declared independently**
> of a resource via `#[AsJsonApiRelations]` + a `RelationsRegistry` (ADR 0026);
> **per-relation endpoint exposure** (`withoutRelatedEndpoint`/
> `withoutRelationshipEndpoint`/`cannotAdd` → handler-enforced `404`/`403`, links
> consistent, ADR 0027); the single global handler **overridable by decoration**
> (ADR 0028); Doctrine **constructor-less instantiation** so entities with required
> constructor args work under the generic engine (`ClassMetadata::newInstance()`,
> ADR 0029); and finally **queryable, paginated related to-many collections** over a
> new `DataProvider::fetchRelatedCollection()` seam — the in-memory provider reads
> the related objects off the parent and applies the shared `CriteriaApplier` +
> array window; the Doctrine provider scopes a **push-down `QueryBuilder`** on the
> related repo (never loading the whole collection) — by the inverse owning FK for a
> single-valued inverse association, or an `IN`-subquery rooted on the related entity
> for an owning-side / many-to-many relation (ADR 0031) — with per-relation default
> pagination resolving relation → related resource → server default (bundle ADR 0030,
> core ADRs 0034–0035 for paginated `RelatedResponse` + the per-relation paginator).
> The remaining related-endpoint gap then closed: **polymorphic** `MorphTo` /
> `MorphToMany` endpoints render through per-object serializer resolution
> (`RelationInterface::resolveSerializer`) and a `PolymorphicSerializer` decorator —
> the to-one resolves its serializer from the related object, the to-many renders
> mixed members; the in-memory provider supports it (reads the mixed collection off
> the parent; a polymorphic to-many carries no shared filter/sort vocabulary so
> `filter`/`sort` 400 while `page` slices), the Doctrine provider throws "unsupported"
> for a polymorphic to-many (members span entity classes — supply a custom provider).
> Bundle ADR 0032, core ADRs 0036–0037 (the decorator needed no transformer change).
> Functional acceptance spans dual-provider conformance suites per slice plus a
> dedicated polymorphic-kernel witness; the whole suite is green on every kernel
> (PHPStan L9 + PER-CS 2.0 + 323 spec-grouped tests). **Phase 5 (v1 consolidation:
> docs, example app, core public-API review) is next.**

0. ✅ Skeleton + thinnest read (`GET /{type}`, `/{type}/{id}`) — forced core's lazy
   resolver + lifecycle-logic extraction + a PSR-7-free render seam. **Done.**
1. ✅ Full read (collections, sparse fieldsets, sort, filters, pagination) —
   forced core's `PaginatorInterface::window()` push-down seam +
   `FilterParamUnrecognized`; Doctrine filter/sort handlers audited vs a real
   QueryBuilder. `include` is deferred to Phase 3 with relationships (no
   relations exist to include yet). **Done.**
2. ✅ Writes (POST/PATCH/DELETE) + the Symfony Validator bridge — forced core's
   error-document status fidelity + settable response status/`NoContentResponse`;
   constraint-vocab translation now **complete** — `When` + the date bounds via
   `Callback` (with a core `when()` field builder), `Timezone` removed from core;
   `Custom` replaced by a typed `constrain()` + class-keyed translator; composition
   (`Sequentially`/`AtLeastOneOf`), cross-field (`CompareField`) and `UniqueEntity`
   (post-hydration entity-validation seam) added — only the `Valid`-cascade gap
   remains for v1. **Done.**
3. ✅ Relationships (S0–S6, both providers). Declared relations + linkage/links
   rendering (`withoutLinks`, `dataOnlyWhenLoaded` over a load-state seam);
   related + relationship **read** endpoints + compound `?include`; relationship
   **mutation** (`PATCH`/`POST`/`DELETE …/relationships/{rel}`) closing the
   `UpdateRelationshipHydratorInterface` gap and finally throwing
   `FullReplacementProhibited`/`RemovalProhibited` via `cannotReplace`/`cannotRemove`
   over a `DataPersister::mutateRelationship()` seam; relationships in whole-resource
   writes; `WhereHas`/`WhereDoesntHave` filters (Doctrine `EXISTS`); and the implicit
   nested-attribute (`Map`) validation cascade. Core ADRs 0024–0030, bundle ADRs
   0015–0020. **Done.** (`include` deferred from Phase 1 landed here.)
4. ✅ Capstone: the generic zero-handler CRUD engine over the SPI — reframed as
   **consolidate + fully capability-compose the type model** (serializer / hydrator
   / relations / provider / persister as independent optional capabilities, nothing
   coupled to `AbstractResource`). `uriType` (ADR 0022); per-type serializer/hydrator
   override + standalone registration (ADRs 0023–0024); per-type operation allow-list
   + operation-gated routing (ADR 0025); standalone relations (ADR 0026); per-relation
   endpoint exposure (ADR 0027); handler override via decoration (ADR 0028); Doctrine
   constructor-less instantiation (ADR 0029); queryable/paginated related collections
   over `fetchRelatedCollection()` (ADR 0030, core ADRs 0034–0035) — extended to
   many-to-many via a Doctrine `IN`-subquery scope branch (ADR 0031); and polymorphic
   (`MorphTo` / `MorphToMany`) related endpoints — the to-one resolves its serializer
   from the related object, the to-many renders members through a `PolymorphicSerializer`,
   in-memory supported, Doctrine throws for a polymorphic to-many (ADR 0032, core ADRs
   0036–0037). **Done.**
5. **(next)** v1 consolidation: docs, example app, and the core public-API surface
   review with this bundle as the integration witness.

**Per-phase handover contract:** a runnable Doctrine+sqlite functional slice; the
enumerated core changes each merged to core `main` + ADR'd *before* the bundle
phase consumes them (consumed immediately on `dev-main` — **no per-phase tag
pin**; the core pin to `^1.0` happens once, at v1, see the
`core-dependency-stays-on-dev-main-until-v1` bundle auto-memory); green PHPStan
L9 + PER-CS 2.0 + the spec-grouped suite on **both** the Doctrine and in-memory
providers.

## Tooling & conventions (inherited from core)

```bash
composer test       # PHPUnit (attributes only, no annotations)
composer phpstan    # PHPStan level 9
composer cs-check   # PHP-CS-Fixer, PER-CS 2.0
```

- **Namespace** `haddowg\JsonApiBundle\` (matches core's lowercase `haddowg\`
  vendor segment). Bundle class: `haddowg\JsonApiBundle\JsonApiBundle` (an
  `AbstractBundle`).
- **Conventional Commits** for every commit and **PR title** (PRs squash-merged;
  release-please drives versioning). While `0.x`, breaking changes bump the minor.
  PR descriptions read as external-contributor prose — no "What/Why" headings, no
  reference to internal planning. Follow `~/.claude/references/commits.md` and
  `~/.claude/references/pull-requests.md`. Rebase with `--force-with-lease`.
- **CS disables `global_namespace_import`** (same as core): reference global
  classes/functions inline (`\Exception`, `\dirname`), never imported.
- **PHPStan level 9.** Consider adding `phpstan/phpstan-symfony` if container/magic
  false positives appear; not installed in the scaffold.
- **CI** mirrors core (PHP 8.3/8.4/8.5 × lowest/highest) and **dual-checks-out**
  the core repo as a sibling so the path repository resolves until core ships to
  Packagist.

## Working with the sibling core

When a slice needs a core change: make it in `../json-api` on its own branch (with
an ADR under `docs/adr/` and tests), get it green there, merge it to core `main`,
then consume it here. During local development the path repository symlinks core,
so changes are visible immediately (after a `git pull` of the sibling on `main`).
The bundle stays on `dev-main` for all of pre-1.0 and pins core only at v1 — see
the `core-dependency-stays-on-dev-main-until-v1` bundle auto-memory.
