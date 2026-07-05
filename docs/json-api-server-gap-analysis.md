# `tobyzerner/json-api-server` vs `haddowg/json-api` (+ Symfony bundle / Laravel package) — comparison & gap analysis

A head-to-head against **`tobyz/json-api-server`** — the closest *philosophical*
competitor we have, because it shares our exact architecture: a framework- and
storage-agnostic JSON:API core with a framework integration layer on top. It is
authored by **Toby Zerner** (Flarum, long-time JSON:API-ecosystem contributor), so
its spec fidelity is high and its design choices are worth taking seriously.

Companion to [`laravel-gap-analysis.md`](laravel-gap-analysis.md) (Laravel JSON:API
5.x) and [`api-platform-gap-analysis.md`](api-platform-gap-analysis.md) (API Platform
4.x). Where a gap recurs it is cross-referenced (`= Laravel #N`) so planning never
double-counts. Findings that touch **core** apply to all three of our packages
(core, the Symfony bundle, and the [`json-api-laravel`](https://github.com/haddowg/json-api-laravel)
package under construction); findings that touch a framework layer are called out per
package.

**Version surveyed:** `json-api-server` **`v1.0.0-rc.1`** (~Jan 2026) on `main`. Facts
below are drawn from the repo's raw doc sources (`docs/*.md`), README, `CHANGELOG.md`,
and Packagist. A handful of json-api-server details could not be confirmed from the
docs and are flagged *(unconfirmed)* rather than asserted.

---

## 0. The lens (read this first)

json-api-server and our stack answer the **same question** — "spec-compliant JSON:API
over my models with the least ceremony" — with the **same top-level shape** (agnostic
core + framework layer). So, unlike the API Platform survey, this is a true
apples-to-apples comparison. Three structural differences frame everything below:

1. **Packaging.** json-api-server is **one package**: the agnostic PSR-15 core *and*
   the Laravel/Eloquent layer (`Tobyz\JsonApiServer\Laravel\*`, incl. `EloquentResource`)
   ship together. We split into **core + one package per framework** (`json-api`,
   `json-api-symfony`, `json-api-laravel`). Their model is lighter to install for a
   Laravel user; ours keeps the core genuinely framework-free and lets a non-Laravel
   framework (Symfony today, others later) be a first-class citizen rather than an
   afterthought. **Deliberate divergence, not a gap.**
2. **Where the data layer lives.** In json-api-server the resource class *is* the data
   layer — it implements `query()`/`find()`/`newModel()`/`create()`/`update()` itself
   (marker interfaces `Listable`/`Findable`/`Creatable`/`Updatable`/`Deletable`).
   `EloquentResource` implements them for you. We keep the store **out** of the
   resource behind a separate `DataProvider`/`DataPersister` SPI resolved per type
   (capability composition). Their approach is more compact for one app; ours
   decouples storage from wire-shape (one entity → many types, standalone
   serializers, a Doctrine reference that any app provider shadows at priority `0`)
   and is what makes our dual-provider conformance witness possible. The Laravel
   plan **explicitly considered and rejected** the json-api-server "resource declares
   `$model`, no SPI" model (Laravel ADR 0002 / PLAN decision 2).
3. **Endpoints: opt-in objects vs. operation allow-list.** json-api-server mounts
   nothing until you add explicit `Endpoint\Index|Show|Create|Update|Delete` objects
   (each with `->visible()/->hidden()` and response hooks). We mount the convention
   set and gate it with the `Operation` allow-list on `#[AsJsonApiResource]` /
   `#[AsJsonApiSerializer]` plus per-relation exposure flags. Same capability,
   inverse default (theirs opt-**in**, ours opt-**out**).

**Bottom line up front:** we **meet or exceed** json-api-server on essentially every
operational axis — includes/N+1, pagination of relationships, custom actions,
testing, validation portability, authorization, extensibility, and DX tooling. It has
a small number of genuinely nice ideas we do **not** have (a composite/union **type
system**, first-class **async** endpoints, **boolean filter groups**, and
**sparse-by-default** fields) — those are the actionable output of this survey. Its
real risk relative to us is **maturity/adoption** (pre-1.0, ~70 stars, ~3 dependents,
effectively single-maintainer), which is a wash — we are pre-1.0 too.

---

## 1. Executive summary — what json-api-server surfaces that our prior surveys did not

Most of json-api-server's surface (cursor pagination, OpenAPI generation, atomic
operations, polymorphism, typed filter operators, default sort, page-size caps, error
pointers) is **already shipped by us or already tracked** in the Laravel/API-Platform
gap docs — so it does not re-open those. Cross-checking its full feature set against
our current `src/` leaves a **short list of genuinely new ideas**:

| # | Idea (json-api-server) | Value | Effort | Layer | Verdict |
|---|------------------------|:---:|:---:|:---:|---------|
| A | **Composite / union attribute types** (`Type\Obj`, `Any`, `AnyOf`, `AllOf`, `OneOf`, `Not`) → JSON-Schema-style variant attributes, projected as OpenAPI `oneOf`/`anyOf`/`allOf` | Med | M–L | core (+ bundle OpenAPI) | **Real gap.** Our richest attribute types are `Map`/`ArrayHash`/`ArrayList`/enum — no union/variant modelling and no `oneOf`/`anyOf` in the generated schema. The single most substantive new idea. |
| B | **First-class async endpoints** (`Create->async()` → `202 Accepted` + `Content-Location`; `Show->seeOther()` → `303`; declarative `Retry-After`) | Med | M | both | **Real gap (partial).** Reachable *imperatively* today by returning a hand-built response from a custom action, but there is no *declarative* async-write affordance. A natural fit — especially for the Laravel package (queued jobs). |
| C | **Boolean filter groups** (`filter[and]`/`[or]`/`[not]`, resource opts in via `SupportsBooleanFilters`) + generic **per-field operator nesting** (`filter[views][gt]=100`) | Med | M | both | **Partial gap / design question.** We do nested operators for `Range`/`DateRange` (`filter[key][max]`) and ship a per-operator convenience library (`GreaterThan`, `Contains`, …), and `WhereHasMatching` covers OR/multi-column on Doctrine — but there is **no arbitrary and/or/not grouping**. Worth a deliberate decision (expressiveness vs. query-safety/complexity), not an automatic build. |
| D | **Sparse-by-default fields** (`->sparse()` — a field omitted from the default fieldset, rendered only when named in `fields[type]`) | Low–Med | S | core | **Real gap.** We have `hidden()`/`writeOnly()` but no "present, but opt-in per request" tier. Useful for expensive computed/derived attributes. |
| E | **`BooleanDateTime` field** (boolean over a timestamp column: `true`→now, `false`→null) | Low | S | both | **Known** — this is exactly `laravel-gap #65 asBoolean()`; **folds into the soft-delete recipe.** No new work. |
| F | **Range pagination** (a cursor window bounded by *both* `after` **and** `before`, with a `rangeTruncated` flag) | Low | M | core | **Minor gap.** Our keyset cursor is one-directional + count-free; a two-sided range window is a niche variant. Build on demand. |

Everything else json-api-server does, we already do or already have on a roadmap.
**Recommended pickups: A and B before v1 (they harden the type system and the write
surface and both improve the OpenAPI output); D as a cheap opportunistic add; C as an
explicit yes/no design decision; E/F note-only.**

---

## 2. Where we already match or clearly exceed json-api-server

This is the "how do we measure up" answer. On the operational axes that decide whether
a JSON:API service survives contact with production, **we lead** — often on things
json-api-server documents as explicit limitations.

**Relationships & includes — decisive lead.**
- **Automatic N+1-safe includes.** json-api-server has **no automatic batching in the
  agnostic core** — you hand-roll a `Buffer` (deferred values); only its Laravel layer
  ships `EloquentBuffer`. We batch-load `?include` (one query per relation per level)
  on **both** the Doctrine and in-memory providers automatically
  (`fetchRelatedCollectionBatch`, `RelatedIncludeBatcher`).
- **Paginated / windowed relationships.** json-api-server **explicitly cannot paginate
  a to-many** — enabling linkage or `includable()` on a to-many "has no pagination"
  and emits the full set (documented limitation). We window included to-many to page 1
  and paginate related/relationship collections via `ROW_NUMBER() OVER (PARTITION BY …)`
  (ADRs 0065/0066), and expose the **Relationship Queries profile**
  (`relatedQuery[rel][sort|filter]`) to order/narrow a relationship from the primary
  request. This is arguably our single biggest capability lead.
- **Countable relations.** We ship `?withCount` (Countable profile) → `meta.total` via
  a pushed-down, batched COUNT with no materialisation (ADR 0052). json-api-server has
  count features only Laravel-side (`WhereCount`/`SortWithCount`), not as
  relationship-count meta *(a generic count-meta feature is unconfirmed in its core)*.
- **Include safeguards.** `max_include_depth` + per-relation `cannotBeIncluded()` +
  root allow-list (ADR 0037). json-api-server's include-depth cap is **not documented**
  *(unconfirmed / likely absent)*.

**Custom (non-CRUD) actions — lead.** We have a first-class action system
(`#[AsJsonApiAction]`, resource/collection scope, typed `inputType`/`outputType`,
`None`/`Document`/`Raw` input, per-action `security`, `asLink` security-aware link
members). json-api-server has **no custom-action endpoint type** — only async/redirect
hooks on the standard endpoints, or a (self-described *immature*) `Extension`.

**Testing — lead.** We ship `JsonApiBrowser` (fluent `assertFetchedOne/Many/InOrder/Exact`,
`assertCreated`, `expectResource($entity)`, `actingAs()`) + a dual-provider conformance
discipline + opis schema-conformance assertions. json-api-server ships **no documented
testing utilities**.

**Validation portability — lead.** Our constraint vocabulary is declared once in core
(framework-neutral VOs) and **executed** per framework — translated to Symfony
Validator in the bundle, and (per the Laravel plan) to `illuminate/validation` rules
with native messages. json-api-server validation is `->validate($v,$fail)` closures +
type constraints, or **bound to Laravel's validator** via `rules()`; there is no
neutral vocabulary, so porting rules to a non-Laravel host is on the author.

**Authorization — lead (and different model).** We evaluate declarative authz at the
right lifecycle points with the **loaded entity in hand** — Symfony Security
expressions (`security:`/`securityCreate:`/… → Voters) in the bundle, Laravel
policies (Gate `viewAny/view/create/update/delete`, per-relation override, dedicated
`policy:` class) in the Laravel plan — plus per-relation and per-action gates.
json-api-server's authorization is **visibility closures** (`Endpoint->visible()`,
field/filter `visible()/writable()`) + Laravel `can()`; expressive but lower-level and
without a policy-object model in the agnostic core.

**Also ahead or at parity, briefly:** richer **id strategies** (client-generated,
app-minted uuid/ulid, opaque encoded ids over a store key, route requirements);
**declarative cache + RFC 8594 deprecation/sunset headers**; **`links.describedby`**
auto-wired to the served OpenAPI doc; **multi-server**; **lifecycle hooks + real
events**; an **extensible filter/sort arm seam** and **query-scoping `DoctrineExtension`**;
**handler decoration**; **strict query parameters**; and a **Doctrine reference layer**
with dual-provider conformance. On **spec compliance** we are at parity or ahead:
both target **1.1** with **atomic operations** — but json-api-server makes you *wrap
your own DB transaction* around `handle()`, whereas we own an all-or-nothing
transaction per participating persister (ADRs 0087–0089), which is more turnkey and
safer. Both do content negotiation, profiles, and pointer-bearing single-error objects.

---

## 3. Where json-api-server has an arguable edge (beyond the §1 build list)

Honest accounting of DX choices a json-api-server user would miss coming to us — none
are feature gaps, but they inform the Laravel package's ergonomics:

- **One-package, one-base-class Laravel onboarding.** `composer require` one package,
  extend `EloquentResource`, done — the resource *is* the schema *and* the data layer.
  For a Laravel dev doing one straightforward app, that is less to learn than
  "core + package + a Provider/Persister SPI". Our answer is that the SPI is invisible
  for the common case (the Doctrine/Eloquent reference pair auto-registers at `-128`),
  but the **Laravel package must make sure the happy path feels as short as
  `EloquentResource`** — the `#[AsJsonApiResource(model:)]` auto-registration (Laravel
  PLAN decision 2 / Phase 2) is what closes this; keep it a headline, not a footnote.
- **The typed composite type system as an authoring primitive** (§1-A) — beyond the
  OpenAPI payoff, `Obj`/`AnyOf`/… read nicely as a way to describe a JSON attribute's
  shape inline. Our `Map` covers structured objects but not unions.
- **Async/redirect as a declarative endpoint concern** (§1-B) rather than a
  hand-rolled response.
- **Compactness of the fluent, everything-on-the-field API** (`->get/->set/->save/
  ->serialize/->deserialize/->validate/->visible/->writable`). We spread the same power
  across the field DSL + serializer/hydrator overrides + hooks + the SPI, which is more
  composable but less "all in one place." Taste, not capability.

---

## 4. Recommendations for the Laravel plan (`json-api-laravel`)

The Laravel `PLAN.md` is already thorough and, on the axes json-api-server would test,
**already ahead of it** — SQL push-down batched includes (better than `EloquentBuffer`),
policy authorization, always-on validation bridge, cursor/keyset pagination, atomic
operations, OpenAPI byte-compatible with the bundle, a Testbench workbench, and a
reshaped testing kit. json-api-server surfaces **no new architectural gap** for the
plan. Concrete adjustments:

1. **Adopt §1-A (composite types) and §1-B (async) as core/bundle work first**, then
   inherit them in the Laravel package for free — they are core-field-DSL and
   response-VO concerns, so building them in core keeps all three packages in parity
   and preserves the byte-compatible-OpenAPI obligation (Laravel ADR 0001).
2. **`async()` is especially Laravel-idiomatic** — a `202 Accepted` create that
   dispatches a queued job is a natural Laravel pattern. If §1-B lands, the Laravel
   package should wire it to the queue (`ShouldQueue`) as the reference implementation,
   the way the bundle would wire it to Messenger.
3. **Guard the "as short as `EloquentResource`" onboarding** (§3, bullet 1). Make the
   Phase-2 `model:` auto-registration + a minimal `AbstractResource` the documented
   quickstart, so the SPI never surfaces in the 90% path. This is the one place
   json-api-server's packaging is genuinely more inviting; neutralise it in the docs
   and the quickstart, not by abandoning the SPI (which PLAN decision 2 rightly keeps).
4. **Decide §1-C (boolean filter groups) once, at the core level.** If yes, it wants
   the `SupportsBooleanFilters`-style opt-in so a resource author controls query
   complexity; if no, say so in the docs (as json-api-server-parity that we
   consciously decline for query-safety), because integrators *will* ask.
5. **Nothing to reverse.** The plan's divergences from the bundle (always-on
   validation, policy authz, SQL-only windowing, Testbench workbench) are all
   *stronger* than json-api-server's equivalents; keep them.

---

## 5. Deliberately not / N/A

- **Single-package Laravel-in-core distribution** — our multi-package split is a
  deliberate posture (framework-neutral core, first-class non-Laravel frameworks). Not
  a gap.
- **Resource-implements-its-own-datastore** (no SPI) — considered and rejected (Laravel
  ADR 0002); the SPI is the platform seam and the conformance witness.
- **`EloquentResource` marker-interface capability set** (`Listable`/`Findable`/…) —
  our capability composition (serializer/hydrator/relations/provider/persister as
  independent tags) is the same idea, decoupled from the resource class.
- **PSR-15-middleware-only extensibility / the immature `Extension` system** — we offer
  a broader, DI-native extension surface (SPI, decoration, arm seams, hooks, events,
  translators, mappers, OpenAPI factory). Their `Extension` is documented as unstable
  ("may change significantly… no support for augmentation of standard API responses").
- **Heterogeneous top-level `Collection` endpoints** — we model polymorphism via
  `PolymorphicSerializer` + per-object `resolveSerializer` (Morph relations); a mixed
  *primary* collection endpoint is niche and reachable via a custom provider + a
  standalone serializer. Note-only.

---

## 6. Scope summary

Benchmarked against `json-api-server` `v1.0.0-rc.1`, our offering is **ahead on every
operational axis that matters in production** — automatic N+1-safe and *paginated*
includes (its documented weak spots), custom actions, testing, portable declarative
validation, entity-aware authorization, and breadth of extensibility — and at parity
or ahead on spec compliance (atomic operations, profiles, cursor pagination, OpenAPI).
The comparison yields **four genuinely new, worth-building ideas** and two note-only
ones: a **composite/union type system** (A), **first-class async endpoints** (B),
**boolean filter groups** (C, a design decision), and **sparse-by-default fields** (D),
with `BooleanDateTime` (E) folding into the existing soft-delete recipe and range
pagination (F) deferred. None re-opens the Laravel/API-Platform surveys; A–D are net-new
and should be decided in **core** so all three packages inherit them. json-api-server's
only structural advantage is a shorter Laravel onboarding, which the Laravel package's
`model:` auto-registration already neutralises — keep it front-and-centre in the docs.
