# Batch windowed related collections in one query per relation (Approach B)

Under the Relationship Queries profile, the `RelationshipWindowBatcher` windowed
each rendered to-many relation to page 1 by looping **M parents x N relations** and
re-driving `DataProvider::fetchRelatedCollection()` per pair — ~`2*M*N` statements
per page (each per-parent fetch ran a count plus a windowed page). That is the #55
collection-include N+1. We added a batched read seam,
`DataProvider::fetchRelatedCollectionBatch(parentType, parents, relation, criteria,
request): RelatedBatch`, and routed the profile/windowed path onto **one batch call
per windowed relation over the whole page** — so the windowing cost is **O(N)**, not
O(M*N). The per-parent windowing loop is **retired**; the plain-include
`IncludePreloader` path is untouched (its routing/ShipMonk drop is a later step).

`RelatedBatch` (bundle, no core change) is the collection twin of a single
`CollectionResult`: a `parentWireId => CollectionResult` map plus a
`for(string): CollectionResult` accessor returning an empty result for a parent with
no related members — keyed by parent **wire** id exactly as `countRelated()` /
`RelationCountBatcher` key their maps, so the batcher reconciles each page back to
its parent through the same wire-id resolution. The batch is driven through the
**primary** type's provider (like the count batcher), so the in-memory witness
identifies the parent through its own store; the criteria configure it as the
per-parent fetch did (a windowed include = merged related vocabulary + a page-1
window), and the count-free vs countable distinction, unknown-key `400`, and shared
`WindowExecutor` tail are all reused verbatim.

**Approach B (locked for the first cut; ROW_NUMBER deferred):** for a page of
parents and one relation, scope the RELATED entity to the whole page, apply the
shared `CriteriaApplier` filters/sorts IN that query, materialize the flat list,
PARTITION it by parent in PHP, then run the shared `WindowExecutor` per parent's
partition (so each parent's window slice / count-free `hasMore` / countable total is
computed in PHP with no further query). The in-memory witness runs the SAME
algorithm per parent (read related off the parent, apply criteria, window), so the
two providers stay structurally equivalent. The over-fetch (a parent's whole related
set materialized to render its page) is identical to what `IncludePreloader` already
does; B strictly reduces statement count.

The Doctrine scope (`RelationScope::scopeBatchToParents()` → `BatchScope`) has two
shapes, both projecting the parent discriminator so PHP can partition:

- **inverse-FK** (single-valued inverse `OneToMany`, the related carries the owning
  FK): ONE query rooted on the related entity, `WHERE related.<owningField> IN
  (:ids)`, the FK projected as the scalar discriminator. Safe as one query because a
  related member belongs to exactly one parent — no ORM root-entity dedup.
- **owning-side / many-to-many**: a related-rooted single query CANNOT work (the same
  member belongs to several parents and ORM object hydration **dedups a root entity
  across rows**, collapsing the shared member to one pair and losing whole parents'
  partitions; a parent-rooted fetch-join with a related `WHERE`/`ORDER BY` hydrates a
  partial collection Doctrine then silently re-loads in full — the fetch-join-filter
  caveat). So it uses a **pair** strategy: a parent-rooted query SELECTs only the
  scalar `(parentId, relatedId)` pairs (scalars never dedup, so the filtered/ordered
  membership is exact), then the distinct related entities are id-loaded in ONE
  further `IN`-query and re-associated per pair, order preserved — two scalar+load
  queries, still O(N) per relation. To let the related resource's **default sort**
  land on the join alias of this parent-rooted query, `CriteriaApplier` now routes a
  default sort through a caller-supplied `$defaultAlias` (inert on every other path —
  `$defaultAlias` is null there, and the `?withCount` count carries no default sort).

The related type's `DoctrineExtensionInterface`s (soft-delete / tenant /
published-only base constraints) apply ON the related entity in BOTH shapes,
matching the related-rooted `fetchRelatedCollection`. For the inverse-FK shape the
query root IS the related entity, so the extensions apply to the builder directly (as
the unbatched path does). For the **pair** shape the query roots on the PARENT, so an
extension reading `$builder->getRootAliases()[0]` would scope the parent — the wrong
entity (a missing-column Doctrine error, or a silently wrong scope on a same-named
column). So a related extension is instead built on a fresh related-ROOTED subquery
(whose root alias IS the related entity, honouring the interface contract) and the
pair query's related membership is constrained `IN` the extension-narrowed related
ids — no extra round-trip, so the budget stays O(N). This mirrors how the criteria
filters/sorts are already alias-routed onto the related join via `$scope->relatedAlias`.

A polymorphic (`MorphToMany`) to-many keeps the existing `fetchRelatedCollection`
boundary: Doctrine throws (members span entity classes — not one scoped query), the
in-memory witness reads the mixed set off each parent. A query-counting Doctrine
kernel test pins the budget — windowing N=2 relations over M=5 parents stays O(N)
(11 total statements for the whole request), well under the ~2*M*N=20 the per-parent
loop ran for the windowing alone. A second Doctrine kernel registers a related-type
extension on the m2m `editors` relation's `authors` and proves the pair-shape batch
scopes the related entity identically to the unbatched single-parent fetch.

A native windowed batch that slices each parent's page **in the database** (a
`ROW_NUMBER() OVER (PARTITION BY parent ORDER BY …)` window function, avoiding the
per-parent over-fetch) is deferred to an optional later step; Approach B's
materialize-then-partition is the portable first cut and already retires the N+1.
