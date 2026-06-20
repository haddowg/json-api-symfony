# Step 6 design — `fetchRelatedCollectionBatch` (the related-fetch consolidation capstone)

> Working design doc (untracked, like `query-building-consolidation-review.md`). From the step-6 design scout
> (run a4785cae, 2026-06-15). Feeds the 6a/6b builds.

## Headline decision: Approach B (first cut); defer ROW_NUMBER

Doctrine ORM DQL has **no native window functions** (`ROW_NUMBER`/`OVER`/`PARTITION BY`/`LATERAL`). A one-statement
per-parent windowed batch would need NativeQuery + ResultSetMapping with raw SQL — which **bypasses the shared
`CriteriaApplier`/`DoctrineSortHandler`** (raw-SQL sort), makes RSM hydration with joins hard, and **gates portability**
on SQLite ≥ 3.25 / MySQL ≥ 8. So:

- **Approach B (CHOSEN):** one query per relation per level — `WHERE parentFk IN (:ids)` (the batched generalisation of
  `RelationScope`; what `IncludePreloader` already does) — apply the shared criteria filters/sorts **in** the query,
  materialize, **partition by parent and slice each parent's window IN PHP**. Fully portable; reuses every consolidated
  primitive (applier, executor, scope, criteria factory); **witness-identical by construction** (in-memory runs the same
  materialize-then-window-in-PHP algorithm). Over-fetch = **exactly** what `IncludePreloader` ships today; B *reduces*
  statement count (#55 fix).
- **Approach A (ROW_NUMBER):** no over-fetch but native-SQL + RSM + portability-gated + bypasses the sort handler.
  **Deferred** to an optional later `WindowStrategy` behind a Doctrine capability flag (B the portable fallback) — the
  seam shape already accommodates it, so deferring forecloses nothing. Build only if a large-related-set consumer needs it.

## The seam

```php
// DataProviderInterface (bundle SPI)
public function fetchRelatedCollectionBatch(
    string $parentType, array $parents, RelationInterface $relation,
    CollectionCriteria $criteria, JsonApiRequestInterface $request,
): RelatedBatch;   // RelatedBatch: array<string parentWireId, CollectionResult> + for(wireId): CollectionResult (empty default)
```
Keyed by **parent wire id** (reuse `RelationCountBatcher`'s wireId↔object reconciliation). **One method, three configs:**
plain include = empty criteria + null window → `WHERE fk IN` no-slice fast-path; windowed include = merged criteria +
page-1 window → IN-fetch + PHP slice; `?withCount` **stays separate** (see below).

- **Doctrine:** batched `RelationScope::scopeToParents` — inverse-FK: `WHERE related.<owningField> IN (:ids)` SELECT the
  owningField discriminator; m2m/owning: parent-rooted join `SELECT parent.<id>, related FROM Parent JOIN parent.<rel>
  related WHERE parent IN (:ids)` (mirrors `countRelated`). Apply criteria → materialize → group by discriminator →
  `WindowExecutor` per group. Polymorphic to-many keeps the existing throw boundary (not batched).
- **In-memory (witness):** per parent: `readValue` → `CriteriaApplier` → `WindowExecutor` → key by wire id. Near-copy of
  proven `fetchRelatedCollection`+`countRelated` code, lifted to a loop.
- **Nested includes:** a bundle orchestrator (`RelatedIncludeBatcher`, successor to `IncludePreloader`) resolves the
  include tree + the ADR-0037 safeguards (lift intact), calls the batch per level, write-backs via `Accessor::set`,
  recurses to the next level.

## ShipMonk replacement (6b)

`EntityPreloader` does association-graph batching (to-one `WHERE id IN`; OneToMany `WHERE mappedBy IN` hung on
`PersistentCollection`; m2m join-table probe; `loadProxies`; auto-fetch-join inverse to-one). **Approach B needs none of
the collection-hydration plumbing** — it reads a flat list and groups in PHP, so the replacement (a batched
`scopeToParents` + partition) is **less** code. Edge cases to cover: a **to-one include arm** (`WHERE id IN` over target
ids, 1:1 partition); polymorphic = skip→lazy (same boundary as today); composite-id = skip→lazy (same); the
`addFetchJoins` nicety is dropped (secondary optimization, never a correctness property — add later if a budget test flags).

## `?withCount` — keep separate, do NOT fold

`?withCount` counts **without** fetching (a collection may `?withCount` a relation it does not `?include`).
`RelationCountBatcher`/`countRelated` (the grouped COUNT, already filtered per step 5b) stays its own seam. Only the
include∩withCount **overlap** could short-circuit (count the already-materialized set) — a one-query micro-saving,
**optional 6c**, low priority.

## Sub-sequencing

- **6a (medium risk):** seam + `RelatedBatch` + in-memory + Doctrine-B (windowed) + route the **profile/windowed** path,
  **retire `RelationshipWindowBatcher`'s per-parent loop**. Budget: windowed-include statements **O(N) per page, not
  2·M·N** (seed M·N ≫ N). Plain includes + ShipMonk untouched. Strong conformance net (the profile suite).
- **6b (HIGH risk — CHECKPOINT before):** fast-path mode + to-one arm + route **plain includes**, **retire
  `IncludePreloader`, drop `shipmonk/doctrine-entity-preloader`**. Budget **unchanged** vs ShipMonk (B over-fetches the
  same rows in the same query count). Port the `IncludePreloadTest` byte-identical-and-bounded witness onto the batch.
  Decide `PreloadsIncludesInterface` fate (recommend: dissolve into the SPI method; orchestrator renders lazily when a
  related type has no batching provider — preserves the opt-out witness).
- **6c (optional, deferrable):** overlap `?withCount` short-circuit and/or the ROW_NUMBER `WindowStrategy`.

## No core change for 6a/6b. Decisions flagged for Greg
1. **B vs A** → B (over-fetch = status quo, portable, reuses the brain). *Proceeding with B.*
2. **ShipMonk drop timing** → in 6b, after 6a proves the infra. *Checkpoint before 6b.*
3. **`PreloadsIncludesInterface` fate** → dissolve into the SPI method + lazy fallback (6b decision).
4. **to-one include arm** → a batch arm keyed by target id (6b).
5. **`?withCount` fold** → keep separate (6c optional).
6. **ROW_NUMBER** → deferred behind a capability flag, B the fallback (6c, on demand).
