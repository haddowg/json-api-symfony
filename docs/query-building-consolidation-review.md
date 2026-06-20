# Read query-building consolidation review

> **Status: working design doc — analysis only, no code change.** Untracked, like
> `docs/laravel-gap-analysis.md`. It feeds a design discussion, not a PR.
>
> **Scope.** Every place the bundle (`json-api-symfony`) and its core (`json-api`)
> build a *read* query — `filter` / `sort` / `paginate` / `count` — for both
> top-level resources and relationship/included collections. The goal (verbatim
> intent): *"a more comprehensive review of the queries — sort/filter/paginate/count
> — both for top-level resources AND for included relationship queries … as clean
> and as efficient as possible. CONSOLIDATE the query-building logic so we don't
> repeat stuff, maybe extend it / REPLACE THE PRELOADER since it doesn't support
> criteria on relationships."*

---

## 1. Current-state map

Read paths × `{filter, sort, paginate, count}`. "Shared" = goes through the one
consolidated surface (`CriteriaApplier`); "re-impl" = the path rebuilds the logic.

| Read path | filter | sort | paginate | count |
|---|---|---|---|---|
| **Top-level collection** `GET /{type}`<br>`DoctrineDataProvider::fetchCollection` (`DoctrineDataProvider.php:162`) / `InMemoryDataProvider::fetchCollection` (`InMemoryDataProvider.php:83` → `applyAndWindow:193`) | ✅ **shared** — `CriteriaApplier::applyFilters` (`CriteriaApplier.php:78`); Doctrine pushes down via `DoctrineFilterHandler` | ✅ **shared** — `CriteriaApplier::applySorts` (`:105`); `defaultSort` fallback | ✅ Offset only; window resolved in **handler** (`CrudOperationHandler.php:246`), pushed as `OffsetWindow`; non-offset → `LogicException` (`DoctrineDataProvider.php:179`) | ✅ **always-on**, no count-free option; `count()` clone-reselect (`:1024`); in-memory `count($items)` |
| **Related collection** `GET /{type}/{id}/{rel}`<br>`DoctrineDataProvider::fetchRelatedCollection` (`:211`) / in-memory (`:88`) | ✅ **shared applier** over merged vocab (related-resource + relation-scoped, ADR 0051); merge **re-impl** in handler (`:573`) | ✅ **shared applier**; merge **re-impl** | ✅ Offset only; window guard **re-impl** verbatim (`:277`) | ⚙️ **capability-gated** (`isCountable`): countable → `count()`; non-countable → count-free `limit+1` probe `countFreePage` (`:311`) |
| **Includes — plain** `?include` (no profile)<br>`IncludePreloader` (`IncludePreloader.php:81`, Doctrine only) | ❌ **none** | ❌ **none** | ❌ **none** (loads whole set, `WHERE id IN (…)` `:197`) | ❌ **none** |
| **Includes — windowed** (profile only)<br>`RelationshipWindowBatcher` (`RelationshipWindowBatcher.php:78`) → re-drives `fetchRelatedCollection` per parent | ✅ via re-driven provider; merge **re-impl AGAIN** (`:374`) | ✅ via re-driven provider; merge **re-impl AGAIN** (`:394`) | ⚠️ **page-1 only** (page pinned, `:331`); per-parent loop (`:105`) | ⚙️ same `isCountable` branch, **re-encoded** at render seam (`:304`) |
| **`?withCount` relation counts**<br>`RelationCountBatcher` (`RelationCountBatcher.php:52`) → `countRelated` (`DoctrineDataProvider.php:352`) | ❌ **ignores `filter[…]`** (raw membership count) | n/a | n/a | ✅ parent-rooted grouped `COUNT … GROUP BY parent` (`:404`); pivot → `pivotCountQuery` (`:450`) |
| **Pivot collection** (belongsToMany w/ pivot fields)<br>`fetchRelatedPivotCollection` (`:511`), Doctrine only | ⚠️ root via **shared applier** (synthetic criteria `:706`); **pivot fields hand-rolled equality only** (`applyPivotFilters:753`), bypass `DoctrineFilterHandler` | ⚠️ **entirely hand-rolled** two-alias interleave (`applyPivotSorts:790`) — re-impl `-`-parse, default fallback, both 400s | ✅ Offset only; guard **re-impl** (`:531`); count-free `countFreePivotPage` **clone** (`:567`) | ✅ `countPivot` `COUNT(DISTINCT)` **clone** of `count()` (`:956`); `?withCount` → 3rd query shape (`pivotCountQuery`) |
| **Pivot linkage / validation seams**<br>`fetchRelatedPivotMap` (`:591`), `fetchRelationshipPivot` (`:617`) | n/a | n/a | n/a (no window) | full `pivotQuery` run, **hydrates then discards far entities** |

**Shared surface = exactly one:** `CriteriaApplier` (filter+sort *matching*).
Everything else — window narrowing, count, count-free probe, the result-shape
decision, the relation-scope query, the vocabulary merge, the paginator-resolution
chain — is **re-implemented per path**. `CollectionCriteria` (the input DTO) and
`CollectionResult` (the output DTO) are shared *shapes* but each provider method
re-derives how to fill them.

---

## 2. Findings

### 2a. Duplication (the same logic, written N times)

| What | Copies | Anchors |
|---|---|---|
| **Window+count execution tail** (`window===null` early-return → `!instanceof OffsetWindow` `LogicException` → count-vs-count-free → `setFirst/MaxResults` → `CollectionResult`) | **4** | `DoctrineDataProvider.php:174`, `:272`, `:526`; `InMemoryDataProvider.php:203` |
| **`OffsetWindow` type-guard + identical message** | **4** | `DoctrineDataProvider.php:179`, `:277`, `:531`; `InMemoryDataProvider.php:208` |
| **`mergeFilters` / `mergeSorts`** (ADR-0051 related+relation-scoped vocab merge) — byte-identical | **2** | `CrudOperationHandler.php:573`; `RelationshipWindowBatcher.php:374` |
| **Whole related-collection `CollectionCriteria` assembly** (merge + defaultSort + window) | **2** | `CrudOperationHandler.php:443`; `RelationshipWindowBatcher.php:253` |
| **Count-free `limit+1` probe / slice / `hasMore`** | **3** | `DoctrineDataProvider.php:311` (`countFreePage`), `:567` (`countFreePivotPage`); `InMemoryDataProvider.php:219` |
| **Clone-reset-reselect COUNT helper** (`count()` vs `countPivot()` differ only by `DISTINCT` + `groupBy` reset) | **2** | `DoctrineDataProvider.php:1024`, `:956` |
| **Paginator-resolution chain** (`relation->pagination() ?? related ?? server->defaultPaginator()`) | **3** | `CrudOperationHandler.php:246`, `:415`; `RelationshipWindowBatcher.php:230` |
| **`inverseOwningField()`** — provider ↔ persister verbatim ("Mirrors the persister's resolver") | **2** | `DoctrineDataProvider.php:1042`; `DoctrineDataPersister.php:577` |
| **`column() ?? name()` relation-property resolution** | many | `:226`, `:373`, `:408`; both batchers |
| **Relation-selection loops** (`windowableRelations` / `countableRelations` / preloader per-relation guards) over `relationsFor` | **3** | `RelationshipWindowBatcher.php:137`; `RelationCountBatcher.php:102`; `IncludePreloader.php:172` |
| **The ENTIRE `CriteriaApplier` contract, by hand, for pivot** (`applyPivotCriteria/Filters/Sorts/validateDefaults` re-do match, `-`-parse, default fallback, `SortingUnsupported`, `SortParamUnrecognized`, `defaultDirectives`) | — | `DoctrineDataProvider.php:686`–`855` vs `CriteriaApplier.php:59`–`195` |
| **`iterable→list` materialization idiom** (`is_array ? array_values : iterator_to_array`) | many | `CrudOperationHandler.php:261`; `RelationshipWindowBatcher.php:273`; `InMemoryDataProvider.php:104`; `IncludePreloader.php:251` |
| **`CollectionResult→page` render arms** (`paginate` / `paginateWithoutCount` / `fromCollection`) | **4** | `CrudOperationHandler.php:295`, `:480`, `:520`; `RelationshipWindowBatcher.php:304` |

**The headline:** the window+count tail and the count-free probe are *the* central
duplication — one `windowAndCount(query, criteria, countCallback, itemsCallback)`
helper collapses 4 + 3 copies. The vocabulary merge + paginator chain are the
second cluster. The pivot path is a wholesale re-implementation of the one shared
surface because the applier is single-alias-only.

### 2b. Inefficiency

1. **Include per-parent query loop (the #55 gap, the biggest one).**
   `RelationshipWindowBatcher::batch` loops M parents × N windowable to-many
   relations and issues one `fetchRelatedCollection` per pair (`:105`,`:270`) — and
   each *countable* relation adds its own COUNT inside that fetch, so up to
   **2·M·N statements** per page. Its own docblock concedes "N relations x M
   parents fetches … a native windowed batch is a future optimization (ADR 0053)".
   This is exactly Greg's "criteria on relationships" pain: the only criteria-capable
   include path is O(parents).

2. **Two disjoint, uncoordinated include mechanisms that never compose.**
   `IncludePreloader` (criteria-FREE, batched, one query/level) handles plain
   `?include`; `RelationshipWindowBatcher` (criteria-CAPABLE, per-parent) handles
   the profile. A filtered/sorted include **cannot** use the efficient batch loader;
   a plain include **cannot** reuse the criteria machinery.

3. **Dueling-mechanism wasted work.** On a profiled collection read,
   `CrudOperationHandler` runs `preloadIncludes()` first (`:283`) — the preloader
   eager-loads the **full** to-many set for every parent — then
   `applyRelationshipWindows()` (`:288`) **immediately re-queries page 1 per parent
   and overwrites the column** (`RelationshipWindowBatcher.php:281`). The entire
   preloaded set is fetched and thrown away.

4. **The preloader cannot bound an include at all.** `WHERE id IN (…)` loads the
   whole association for every parent (`IncludePreloader.php:197`); a parent with
   thousands of children over-fetches unboundedly. There is no path to a
   filtered/sorted/limited include outside the profile.

5. **Always-on COUNT on the top-level path.** Every windowed top-level page issues
   a second round-trip COUNT (`:1024`), even for "page 1 of a small table" and even
   when the client never reads `last`/`meta.page.total`. The related path already
   has a count-free mode; the top-level path **cannot opt into it**.

6. **`?withCount` ignores `filter[…]`.** `countRelated` counts raw membership
   (`:404`), so `meta.total` can disagree with what a filtered related-collection
   endpoint paginates — count and collection use divergent, un-shareable builders.

7. **Divergent query shapes for identical membership.** The related fetch roots on
   the *related* entity (inverse-FK or IN-subquery, `:240`); `countRelated` roots on
   the *parent* (LEFT JOIN GROUP BY, `:404`); pivot `?withCount` is a *third* shape
   rooted on the *association* entity (`:450`). The docblocks assert they stay
   consistent; nothing enforces it.

8. **Pivot over-hydration.** `fetchRelatedPivotMap` / `fetchRelationshipPivot`
   (linkage + validation seams) run the full grouped `pivotQuery` and hydrate the
   far entities they then discard (`:591`,`:617`) — they need only ids + pivot
   scalars. The pivot endpoint always `GROUP BY`s + `COUNT(DISTINCT)` even on the
   never-paginated linkage render.

9. **No cursor/keyset anywhere.** `WindowInterface` is polymorphic but only
   `OffsetWindow` exists; every provider hard-asserts it. Deep-offset pages degrade,
   and the count-free `limit+1` probe — *which is exactly the keyset primitive* — is
   re-implemented per method instead of being a windowing capability (#46/#55).

10. **Window resolved twice per request.** The handler resolves the window to push
    down (`CrudOperationHandler.php:247`) and `paginate()` re-derives it from the
    request internally (`OffsetPaginator.php:89`) — the window is not a single
    source of truth, and request pagination params are parsed twice.

---

## 3. Proposed unified architecture

A single criteria/query-building engine, with **one** post-`apply` tail, **one**
relation-scope primitive, **one** count helper, and **one** batched criteria-capable
related-fetch that subsumes both include mechanisms. The existing `CriteriaApplier`
stays the matching brain; what's missing is everything *around* it.

### 3.1 The four new seams

```
                 CollectionCriteria  (input DTO — already exists; gains aliasRouter + countable + relationScope)
                          │
   ┌──────────────────────┼─────────────────────────────────────────────┐
   │                      │                                              │
RelationCriteriaFactory   CriteriaApplier (alias-aware)        WindowExecutor
 - ADR-0051 merge ONCE    - filter/sort matching ONCE          - null / offset-guard / count
 - paginator chain ONCE   - routes a directive to an ALIAS       vs count-free(limit+1) ONCE
 - relation→provider+vocab  (root | pivot | …)                  - emits CollectionResult
   resolution ONCE                                              - WindowStrategy: Offset | Cursor
                                                                  (#46) | RowNumberBatch (#55)
                          │
                  RelationScope (Doctrine)            CountQuery (Doctrine)
                  - inverse-FK | IN-subquery | pivot  - count(qb, distinct: bool)
                    join — ONE primitive               - reused by page-total AND ?withCount
                  - reused by fetch AND count
```

1. **`WindowExecutor` (bundle, provider-parameterized).** Owns the entire tail
   that is copy-pasted 4×. Signature roughly:

   ```php
   $executor->run(
       criteria:    $criteria,           // carries window + countable flag
       count:       fn() => $this->count($builder /*, distinct */),
       page:        fn(int $offset, int $limit) => $this->items((clone $builder)
                        ->setFirstResult($offset)->setMaxResults($limit)),
       probe:       fn(int $offset, int $limit) => $this->items((clone $builder) // limit+1
                        ->setFirstResult($offset)->setMaxResults($limit + 1)),
   ): CollectionResult;
   ```
   The executor decides: no window → plain; offset + countable → count + page;
   offset + count-free → probe + slice + `hasMore`; (future) cursor → keyset probe.
   `fetchCollection`, `fetchRelatedCollection`, `fetchRelatedPivotCollection`, and
   in-memory `applyAndWindow` all call it. The `OffsetWindow`/`CursorWindow`
   narrowing lives **here once** — adding cursor (#46) is a new `WindowStrategy`,
   not a 4-site edit.

2. **`RelationCriteriaFactory` (bundle).** Builds the related-collection
   `CollectionCriteria` once: the ADR-0051 merge (related-resource `filters()`/
   `allSorts()`/`defaultSort()` + relation-scoped `filters()`/`sorts()` + pivot
   keys) **and** the 3-tier paginator chain (`relation ?? relatedResource ??
   server`). Consumed by the related endpoint arm, the window batcher, and the count
   batcher. Kills the duplicated `mergeFilters`/`mergeSorts` and the 3-copy paginator
   chain. The only per-caller variation (plain `?sort` vs profile `relatedQuery[…]`
   projected to plain) becomes the single normalized input.

3. **Alias-aware `CriteriaApplier`.** A filter/sort directive carries a target
   *alias* (default `resource`). The applier routes `pivot.<col>` vs `resource.<col>`
   in one request-ordered pass. This deletes `applyPivotCriteria/Filters/Sorts/
   validateDefaults` (~150 lines, `DoctrineDataProvider.php:686`–`855`) — pivot
   registers its fields as alias-tagged filters/sorts and inherits the exact spec
   semantics (`SortingUnsupported`, `SortParamUnrecognized`, default fallback) and
   the **operator-capable** `DoctrineFilterHandler` (closing the pivot
   equality-only divergence) for free.

4. **`RelationScope` + unified `count()` (Doctrine).** One "scope a related query to
   a parent(s)" primitive (inverse-FK fast-path | IN-subquery | pivot-join),
   consumed by *both* the fetch and the count, so `?withCount` runs `COUNT` over the
   *same* scoped+filtered builder — `filter[…]` honoured for free, and the
   parent-rooted vs related-rooted divergence gone. `count(qb, distinct: bool)`
   folds `count()` + `countPivot()`.

### 3.2 Criteria on relationships — the batched related-fetch (replaces the preloader)

The key new provider seam:

```php
interface DataProviderInterface {
    /** Load the related set for a WHOLE page of parents, with criteria + per-parent window. */
    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,                 // the page of parents
        RelationInterface $relation,
        CollectionCriteria $criteria,   // empty + no window == today's plain-include fast path
    ): RelatedBatch;                    // parent-id → CollectionResult
}
```

- **Doctrine.** The deferred native window (#55): one query partitioned by parent FK
  with a `ROW_NUMBER() OVER (PARTITION BY parent ORDER BY <sort>) <= limit`
  predicate (or `LATERAL` where available), `COUNT(*) OVER (PARTITION BY parent)`
  folded into the *same* statement for `?withCount`. Built on the **one**
  `RelationScope` primitive. Plain include = the same batch with **empty criteria +
  no window** → degrades to today's `WHERE fk IN (…)` set-load (so the ShipMonk fast
  path is preserved as a *mode*, not a separate mechanism).
- **In-memory.** Reads each parent's related set, runs the shared `CriteriaApplier`
  + `WindowExecutor` per parent (already O(parents) and correct — the witness is
  unchanged).

**Outcome:** `IncludePreloader` and `RelationshipWindowBatcher` collapse into one
criteria-capable batch. Plain include, windowed include, and `?withCount` are three
configurations of one mechanism; the dueling-mechanism waste (preload-then-overwrite)
disappears; an include can carry a per-relation default sort/limit **without the
profile**; and `page` on includes (today's "page-1 only") becomes a true per-parent
window in one place.

### 3.3 How each current path collapses

| Today | Becomes |
|---|---|
| `fetchCollection` tail (`:174`) | `apply` (shared) + `WindowExecutor::run` |
| `fetchRelatedCollection` (`:211`) | `RelationScope` + `apply` + `WindowExecutor::run` |
| `fetchRelatedPivotCollection` (`:511`) + `applyPivot*` (`:686`) | `RelationScope(pivot)` + **alias-aware** `apply` + `WindowExecutor::run` |
| `IncludePreloader` (`:81`) | `fetchRelatedCollectionBatch` with empty criteria (fast-path mode) |
| `RelationshipWindowBatcher` (`:78`) | `fetchRelatedCollectionBatch` with criteria + window |
| `RelationCountBatcher` / `countRelated` (`:352`) | `COUNT(*) OVER (PARTITION BY parent)` projection of the batch, over the shared scope |
| `count()` / `countPivot()` (`:1024`/`:956`) | one `count(qb, distinct)` |
| `countFreePage` / `countFreePivotPage` / in-memory probe | one `WindowExecutor` count-free branch |
| `mergeFilters/Sorts` ×2 + paginator chain ×3 | one `RelationCriteriaFactory` |

---

## 4. Key decisions / forks for Greg

**D1 — Preloader: replace, extend, or keep-alongside.**
*Recommendation: **replace** with the batched criteria-capable seam, preserving the
no-criteria `WHERE id IN (…)` set-load as the engine's default mode.* "Extend the
ShipMonk preloader" is a dead end — it has no concept of a per-parent window, so
filtered/sorted/paginated includes can't ride it. "Keep alongside + add a criteria
path" perpetuates the two-mechanism split and the preload-then-overwrite waste. One
seam with a fast-path mode gives criteria-on-includes *and* keeps the cheap full-set
load when no criteria are present.
*Fork:* ShipMonk's `EntityPreloader` does association-graph batching the bundle
would otherwise hand-roll. Option A — drop it, hand-write the `WHERE fk IN (…)`
loader (simple; full control; one less dep). Option B — keep ShipMonk *inside* the
engine's no-criteria mode only, fall to the native window query when criteria/window
are present (less new code; the dep survives as an internal detail). **Lean A** for a
single coherent engine; B is the lower-risk first step.

**D2 — Where does the engine live: core (storage-agnostic) or bundle/Doctrine?**
`CriteriaApplier`, `WindowExecutor`, `WindowStrategy`, `CollectionResult` are
genuinely storage-agnostic and could move to **core** (sharpening core's public read
API as the v1 witness work intends). `RelationScope`, the `ROW_NUMBER` batch, and
the count helper are **Doctrine-specific** and stay in the bundle. The fork is
whether to introduce a core "QueryBuilder-spec" abstraction (a storage-agnostic
description of a windowed/filtered/sorted query that providers compile). *Recommendation:
keep the **spec/criteria DTOs + executor contract in core**, leave **compilation in
the provider** — do NOT build a core query-AST; that's a much larger, riskier surface
and the bundle is the only consumer pre-1.0.* Promote `WindowInterface` +
`CursorWindow` into core now so cursor (#46) has a home.

**D3 — Cursor/keyset (#46) now or later.** The unified `WindowExecutor` makes cursor
a single new `WindowStrategy` instead of a 4-site change. *Recommendation:* land the
consolidation **first** (offset only), with the executor seam shaped so cursor drops
in — then #46 is a contained follow-up, not a rewrite.

**D4 — Always-on count vs count-free top-level.** Lifting the related path's
count-free mode into the shared executor lets a top-level resource opt out of the
COUNT (a `countable()`/`countsBy()` resource capability). *Recommendation:* yes —
it's free once the executor is shared and directly removes the "repeated COUNT"
inefficiency.

**D5 — `?withCount` honouring `filter[…]`.** Routing the count through the shared
`RelationScope`+filtered builder changes today's behaviour (raw membership →
filtered count). This is *more correct* but is an observable change. *Decision
needed:* is the filtered count the intended semantic for `?withCount` alongside a
filtered collection? (Almost certainly yes — but it's a behaviour change to flag.)

---

## 5. Risk + sequencing

This touches **every** read path, so land it strictly incrementally, both providers
green at every step. The regression net is the **`QueryCountingDoctrineKernel`
query-budget probes** — assert the statement count per scenario *before* each step
and tighten the budget *after* (e.g. the M·N include loop must drop to O(depth));
the dual-provider conformance suites (in-memory witness ≡ Doctrine) guard semantics.

Suggested order (each step is independently shippable, both providers green):

1. **`WindowExecutor`** — extract the window+count tail behind the existing
   behaviour. Pure refactor, no semantic change; budgets unchanged. Collapses the
   4+3 copies. *Lowest risk, highest dup-reduction — do first.*
2. **`count(qb, distinct)`** — fold `count()`/`countPivot()`. Pure refactor.
3. **`RelationCriteriaFactory`** — single merge + paginator chain. Pure refactor
   across handler + both batchers.
4. **`RelationScope` primitive** — unify fetch + count scoping; route `?withCount`
   through the filtered scope (**D5 behaviour change — gate + conformance test**).
5. **Alias-aware `CriteriaApplier`** → delete the pivot hand-rolled applier. Pivot
   conformance suite is the witness; watch the pivot `?sort`/`filter` budget.
6. **`fetchRelatedCollectionBatch` (the big one)** — introduce the seam, route the
   *profile* window path onto it first (replacing the per-parent loop; budget should
   collapse M·N → O(depth)), then route *plain includes* onto it (retiring
   `IncludePreloader`; budget unchanged in fast-path mode), then fold `?withCount`
   into the partition projection. Land Doctrine native-window behind a capability
   flag with a portable per-parent fallback so a DB lacking window functions still
   works.
7. **Cursor (#46)** — new `WindowStrategy`, contained.

**Effort (rough):** steps 1–3 ~1–2 days each (mechanical, well-covered); step 4 ~2–3
days (behaviour change + scope unification); step 5 ~2–3 days (alias routing + pivot
re-derivation); step 6 ~1–1.5 weeks (the native window batch + retiring two
mechanisms + budget tightening) and is where most of the value and most of the risk
sit. Cursor (#46) separate. The first three steps de-duplicate ~60% of the repetition
with near-zero behavioural risk and can land independently of the batch rework.

---

## Anchors index

- Top-level: `DoctrineDataProvider.php:162`, `:1024`; `InMemoryDataProvider.php:83`,`:193`; `CrudOperationHandler.php:246`
- Related: `DoctrineDataProvider.php:211`,`:240`,`:270`,`:277`,`:291`,`:311`,`:1042`; `CrudOperationHandler.php:443`,`:573`
- Includes: `IncludePreloader.php:81`,`:197`; `RelationshipWindowBatcher.php:78`,`:105`,`:253`,`:281`,`:374`; `CrudOperationHandler.php:283`,`:288`
- Count: `RelationCountBatcher.php:52`,`:102`; `DoctrineDataProvider.php:352`,`:404`,`:450`,`:956`,`:1024`
- Pivot: `DoctrineDataProvider.php:511`,`:567`,`:647`,`:686`,`:753`,`:790`,`:898`,`:956`,`:591`,`:617`; `PivotAssociationResolver.php:63`; `PivotFields.php:67`
- Shared/core: `CollectionCriteria.php:36`; `CriteriaApplier.php:59`,`:105`,`:153`; `CollectionResult.php:34`; `../json-api/src/Pagination/PaginatorInterface.php:23`, `WindowInterface.php:19`, `OffsetWindow.php:17`, `CursorPaginator.php:70`
