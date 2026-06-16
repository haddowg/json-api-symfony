# Bound windowed includes with a ROW_NUMBER batch query

The Approach B windowed-include batch (ADR 0061) retired the per-parent N+1 but
materialised **each parent's whole related set** then sliced the page in PHP — for a
windowed include on a large relation (5 newest comments per post where a post has
thousands) this over-fetches massively, and the relationship-pagination total (and any
`?withCount` overlap) was the page size, not the real cardinality. We replaced the
windowed arm of `DoctrineDataProvider::fetchRelatedCollectionBatch()` with a **bounded
native ROW_NUMBER batch**: for a page of parents and one relation, ONE native SQL query
windows every parent in the database to ~`limit` rows each and returns the REAL
per-parent total — so the fetch is bounded and the total is the true pre-window count.
The **plain** include fast-path (no window, `WHERE fk IN (:ids)`) is untouched; only the
windowed batch path branches.

The native shape is a portable **derived-table window** (no CTE — derived tables run on
every targeted engine: MySQL ≥ 8, MariaDB ≥ 10.2, SQLite ≥ 3.25, any PostgreSQL):

```
SELECT * FROM (
  SELECT <related columns>,
         ROW_NUMBER() OVER (PARTITION BY <discriminator> ORDER BY <sort>, <pk> ASC) AS jsonapi_rn,
         COUNT(*)     OVER (PARTITION BY <discriminator>)                            AS jsonapi_total,
         <discriminator> AS jsonapi_parent_id
  FROM <related table> [JOIN <join table>]
  WHERE <discriminator> IN (:parentIds)
) w WHERE w.jsonapi_rn <= :limit
```

It hydrates through a Doctrine `NativeQuery` + `ResultSetMappingBuilder` (DQL has no
window functions): the entity SELECT comes from `generateSelectClause()` (never a
hand-written `SELECT *`, which would drop columns), the three window scalars append via
`addScalarResult`, and the outer `SELECT *` preserves the generated column aliases.
`jsonapi_total = COUNT(*) OVER` the full partition is independent of the limit — the real
total feeding both the relationship-pagination total and the `?withCount` overlap (they
agree because both count the same membership). The ORDER BY appends a **PK tiebreak** so
the partition scan is reproducible at all; the SAME tiebreak now lands in the in-memory
witness's batch/window path, so the two providers are **provably** identical on ties, not
coincidentally identical when insertion order equals PK order. Nullable sort columns reuse
the keyset path's portable `CASE WHEN c IS NULL` term to match PHP's `null <=> value`.

Two discriminator shapes mirror `RelationScope`: an **inverse-FK** `OneToMany` partitions
by the related table's parent FK and hydrates the entity inline (one statement — a member
belongs to one parent, so no cross-partition dedup); an **owning-side / many-to-many**
relation joins the join table and partitions by the join table's parent column, but
selects the related id as a SCALAR and id-loads the distinct entities in one further query
(the ORM object hydrator dedups a root entity across the whole result, so a member shared
across parents would collapse and lose a partition — the same dedup that drove ADR 0061's
pair shape).

**The key trade-off — a pragmatic split, not full native-with-filters.** The native query
is built only for a truly PLAIN windowed include (no effective filter, no query extension,
monomorphic, single-id parent/related) — the overwhelming common include shape (a
relatedQuery sort + a page-1 window). A **filtered** windowed include, an **extended**
related type, and `window_functions: off` all route to a per-parent **bounded fallback**:
a loop over the proven single-parent `fetchRelatedCollection()`, each a real `LIMIT`
push-down through the existing DQL applier. This keeps witness-equivalence (the filtered
path reuses the exact DQL filter execution the endpoint runs) and keeps the fetch BOUNDED
on both branches (M real-`LIMIT` queries, never the whole-set materialise) — so even the
fallback strictly beats Approach B. The two rejected alternatives: hand-translating the
whole filter vocabulary (8 `Where` operators + the platform-portable `LOWER`/`LIKE`/`ESCAPE`
fold + `WhereHas` `EXISTS`) into a parallel native handler forks the single-witness
guarantee into two executors and re-litigates portability the DQL path already solved;
wrapping DQL-emitted SQL with `ROW_NUMBER` depends on Doctrine's opaque, version-fragile
generated column/table aliases for the PARTITION BY / ORDER BY.

`json_api.doctrine.window_functions` (default `true`) gates it. There is **no
auto-detection** (no probe/cache/fallback-on-error per the locked design): on an engine
without window functions the default `true` throws a normal 500 at the first windowed
include (the kernel logs it; the message names the version floors and points at the off
switch), and the fix is to set it `false`. Functional acceptance runs identical assertions
against the in-memory witness and the Doctrine kernel with window functions ON and OFF —
both byte-identical to the witness — covering both shapes, a countable and a non-countable
relation, ties, a filtered windowed include, and a bounded-fetch proof (the SQL carries
`ROW_NUMBER` and `jsonapi_rn <= :limit`, one statement per relation, never one per parent).
This is a Doctrine-provider-internal slice: no core change — `WindowExecutor`,
`CollectionResult`, `RelatedBatch`, and the `CriteriaApplier` seam already carry everything.
