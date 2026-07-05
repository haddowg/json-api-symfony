# Design — `on()` flattened related attributes (multi-hop to-one) + `computedUsing()`

Closes Laravel-parity **L#21** (eager-load a derived attribute's backing relation) and
**L#22** (flatten an attribute from a related model — was parked Tier 4). **L#23
(`with()` / `alwaysLoadRelationships()` is DROPPED** — decided 2026-06-20: its only
genuinely-unserved use cases were niche/anti-pattern, and it dragged in a
Relationship-Queries-profile overwrite interaction + nested-validation + visible-to-many
contradiction rules that did not pay for themselves. `on()` flattening + `?include` cover
the real cases; the multi-hop scalar case is now served by `on()` dot-paths.)

## The attribute trio (core)

| Declaration | Read | Write | Eager-load | Cast |
|---|---|---|---|---|
| `Str::make('title')` | `model.title` | `model.title` | — | yes |
| `Str::make('authorName')->on('author')` | `model.author.name` | `model.author.name` | `author` (implicit) | yes |
| `Str::make('countryName')->on('publisher.country')` | `model.publisher.country.name` | same | `publisher.country` (implicit) | yes |
| `Str::make('displayName')->computedUsing(fn($m,$req,$n)=>…)` | closure | read-only | — | no |

`on()` and `computedUsing()` are **mutually exclusive** (guard at build).

## `computedUsing(\Closure $cb)` — core
- Sugar = `computed()` (column=null) + the value closure + read-only on create AND update.
  Keep `computed()`/`extractUsing()`/`serializeUsing()` as the lower-level primitives.

## `on(string $path)` — core read/write + bundle eager-load (multi-hop to-one)
- `$path` is a `.`-separated chain of **declared to-one** relations: `'author'` (single hop)
  or `'publisher.country'` (multi-hop). The attribute's own `column() ?? name()` is read/
  written on the FINAL related model in the chain.
- **Every segment must be a declared `RelationInterface` and to-one** — validated **fail-loud
  at boot / container warm-up** (a developer-facing `\LogicException`): an unknown segment →
  throw (typo must not no-op); a **to-many** segment → throw (`on()` flattens a scalar from a
  to-one chain — a to-many is not flattenable; use `?include`). A segment may be `hidden()`
  (the idiomatic "internal association" — then it never renders as a relationship and is not
  filterable, so the flatten is conflict-free) or visible. No SPI change.
- **Read** (`serialize`): resolve the chain `model -> seg1 -> … -> segN` via the owning
  resource's `relationNamed()` at each hop (honours each relation's `column()`/`storedAs()`),
  then read the field's `column() ?? name()` off the final related model **with the normal
  `serializeValue()` cast**. Any intermediate null short-circuits → attribute value `null`
  (lenient).
- **Write** (`hydrate`): resolve the chain; if **any hop is null → throw the 422-mapped
  `RelatedAttributeOwnerMissing`** (require-exists per hop — Laravel-faithful, no
  auto-instantiate); else `Accessor::set(finalRelated, column()??name(), deserialize($value))`.
  The final related entity is eager-loaded/associated, so it is mutated in place: Doctrine UoW
  auto-persists the dirty loaded entity on flush; in-memory shares the reference. **No related-
  persister/SPI change.** (Writing through `on()` mutates the SHARED related entity — the same
  semantics as single-hop, just deeper; document it.)
- **Hydration order**: `on()` attributes hydrate **after** relationships, so a first-hop
  relationship associated in the same request body is visible. (Already in place; keep it.)

## Eager-load mechanism (bundle) — driven solely by `on()` paths
- Core capability `DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths(): list<string>`,
  implemented by `AbstractResource` = the **dedup set of every field's `on()` path**. (No
  `alwaysLoadRelationships()` arm — that is removed.) Instanceof-checked (bare/standalone
  serializer → none, skip), mirroring `DeclaresFieldNamesInterface`.
- `RelatedIncludeBatcher` reads it and batch-loads each `on()` path **segment by segment**
  (the to-one chain: load seg1 across the page, 1:1-partition, then seg2 across those, …) via
  the existing `fetchRelatedCollectionBatch` to-one fast-path (`WHERE id IN`), writing each
  level back via `Accessor::set`. O(depth) queries, never per-row. Because every segment is a
  to-one, there is **no windowed-to-many interaction and no contradiction** — the eager set is
  purely a query-plan optimisation, never altering the rendered document (rendering stays gated
  on the transformer's `isIncludedRelationship`, which `on()` backings never enter; a `hidden`
  backing is not even a rendered path).
- Targets resolve against the **hidden-inclusive** declared-relation set
  (`relationNamedIncludingHidden`), since an `on()` backing is idiomatically `hidden()`.
- The eager set **bypasses the client-include safeguards** (author-declared, trusted).
- **No SPI change.** In-memory runs the same path (no-op for perf; write mutates the shared
  reference).

## Relationship-rendering / filtering interaction (VERIFIED against current code — must be preserved)
An `on()` backing relation MAY be `hidden()` (idiomatic internal association) OR a **visible,
includable, filterable** relationship — both are first-class. The eager-load coexists with
normal relationship rendering with no special-casing, because the chain is to-one:
- **Eager-load is invisible to rendering.** A to-one's linkage is rendered independent of load
  state (`dataOnlyWhenLoaded=false` → `AbstractRelation::shouldDeferLinkage()` early-returns →
  the load-state predicate is never consulted). So eager-loading an `on()` backing NEVER flips
  its linkage; the relation renders exactly per its own config (hidden → nothing; visible →
  linkage as normal; `withoutLinks()` → none). Honour the rendering mechanics — do NOT force
  linkage.
- **No re-query when also `?include`d.** The eager pass and the include walk SHARE the per-level
  `$loaded` guard, so an `on()` backing that is also `?include`d reuses the eager-loaded value
  (`collectLoadedTargets`) — one load, not two.
- **`on()` reflects a to-one relationship filter automatically.** A `relatedQuery[<rel>][filter]`
  that excludes a to-one writes `null` onto the parent's property (`Accessor::set`), and this
  runs (in `applyRelationshipWindows`) BEFORE render; `on()` reads the LIVE property at render,
  so a filtered-out target yields a `null` flattened value — consistent with the nulled linkage.
  This is the desired behaviour (a filtered relationship filters its flattened attribute too) and
  it falls out of the architecture: the conformance must PIN it, not re-implement it.
- Multi-hop: a first-hop null (filtered or genuinely absent) short-circuits the whole chain to
  `null`. (Filtering an intermediate nested hop via the profile is not an addressable path and is
  out of scope.)

## OpenAPI / schema
- An `on()` attribute is a normal flattened attribute → appears in the schema and (if writable)
  the request body. A `hidden` backing relation is not a relationship; a VISIBLE backing IS a
  normal relationship in the schema (unchanged by `on()`).
- `computedUsing` → read-only attribute in the schema.

## Acceptance (dual-provider conformance + Doctrine query-budget)
- Single-hop `on('author')` read flatten identical both providers; `author` not a rendered
  relationship when hidden.
- **Multi-hop `on('publisher.country')`** read flatten identical both providers; both hops
  eager-batch-loaded (Doctrine budget **O(depth)**: 1 publisher IN-load + 1 country IN-load, 0
  per-row), document byte-identical to the un-flattened resource.
- Write-existing updates the FINAL related model (assert via re-fetch), single and multi-hop,
  both providers.
- Write with a null hop → 422 `RELATED_ATTRIBUTE_OWNER_MISSING` at the attribute pointer, both
  providers.
- `computedUsing` read-only (write ignored/422; value derives from the closure).
- **Fail-loud validation** (warm-up, both providers): an `on()` path with an unknown segment →
  `\LogicException`; an `on()` path with a **to-many** segment → `\LogicException`. A valid
  to-one chain (hidden or visible) → accepted.
- **Visible backing interaction** (both providers): an `on('author')` over a VISIBLE `author`
  to-one relationship — `author` renders its linkage as a normal relationship, the flattened
  `authorName` is present, and the two are consistent; with `?include=author` the author is
  compound-included with NO second query (assert via query budget), `authorName` still present;
  with `relatedQuery[author][filter]` excluding the target, the `author` linkage is `null` AND
  `authorName` is `null` (the filter reflects through to the flattened attribute) — pin this,
  it must hold by construction.
- **No `with()` / `alwaysLoadRelationships()` anywhere** — removed from core + bundle + docs +
  ADRs.

## Conventions
- No SPI signature change. Core ADR 0082 + bundle ADR 0085 (UPDATE both: drop `with()`, add
  multi-hop `on()`). Conventional Commits; PER-CS 2.0; PHPStan L9; no global imports
  (`\Closure`/`\LogicException` inline). Commit unsigned-local (`git -c commit.gpgsign=false`);
  AMEND the existing feature commits (one core, one bundle). Clear
  `sys_get_temp_dir()/json-api-symfony-{tests,examples}` after any DI/kernel edit.
- Standing rule [[no-unapproved-deviation-from-agreed-plans]]: flag any blocker as a review
  NO-GO; do not silently substitute.
