# Window a to-many relationship only when its linkage data renders

The Relationship Queries profile windows a primary resource's to-many relationships
to page 1 of their `relatedQuery`-ordered/filtered set (ADR 0053), batched once per
relation (ADR 0061). The original `RelationshipWindowBatcher::windowableRelations()`
windowed **every** monomorphic to-many of the primary type regardless of whether that
relationship's linkage `data` actually renders in this document — so a `relatedQuery`
sort/filter on a **lazy, links-only, not-included** to-many still ran a
`fetchRelatedCollectionBatch` window query and wrote page 1 back onto the parent's
relation property.

That write was both a wasted query **and** a linkage leak. Empirically (verified on the
Doctrine kernel): writing the windowed page 1 — a plain `ArrayCollection` — onto a lazy
Doctrine association property replaces the uninitialised `PersistentCollection`, so the
storage load-state predicate (`DoctrineRelationshipLoadState`) flips from "not loaded"
to "loaded". Core's `AbstractRelation::shouldDeferLinkage()` then stops deferring, and
the relationship renders its **filtered** linkage `data` — data the lazy default
(`dataOnlyWhenLoaded`, core ADR 0067) omits as links-only — for a relation the client
never `?include`d.

The decision: window (and filter) a to-many **only when its linkage data renders for
this request** — when the relation is included at the primary level
(`isIncludedRelationship('', name, defaults)`, honouring default-includes) OR it renders
data unconditionally (`emitsDataOnlyWhenLoaded() === false`, the `withData()` opt-out).
A lazy, links-only, not-included to-many is no longer windowed: no fetch, no property
mutation, so it renders links-only exactly as the lazy default intends, and the filter
cannot leak. Default-includes are resolved off the page's first parent as the
representative, mirroring `RelatedIncludeBatcher`.

Two paths are deliberately **left untouched**, because each is already naturally gated:

- **The to-one nulling path** (`toOneFilteredRelations` / `nullExcludedToOnes`, ADR
  0068). A to-one's linkage renders by default (a `BelongsTo` is eager — its id is on
  the owner) and a hidden to-one is not addressable, so a filter-excluded to-one is
  correctly nulled regardless.
- **The count path** (`RelationCountBatcher`, ADR 0060). A `?withCount` of a filtered,
  not-included to-many must still return the **filtered** count, which it computes
  count-only with no load — independent of the window. Gating the window must not (and
  does not) regress it; a dual-provider regression case asserts the filtered count is
  still reported for a not-windowed relation.

To preserve the endpoint's error behaviour, an **unknown** relatedQuery sort/filter
**key** on a now-unwindowed (links-only) to-many must still `400` —
an unrecognised key is a client error whether or not the linkage renders, and the
window fetch previously surfaced that `400` as a side effect. So
`validateUnwindowedRelatedQueryKeys()` validates the keys of every addressed
monomorphic to-many that the rendered-data gate excluded, against the same merged
vocabulary the fetch would (`RelationCriteriaFactory::criteriaFor`), with **no query and
no property mutation** — mirroring `CriteriaApplier`'s key recognition exactly so the
gated and fetched paths agree. Filter-value validation rides the same pass.

Dual-provider conformance (`RelationshipQueriesConformanceTestCase`): a `relatedQuery`
filter on a not-included lazy to-many does **not** apply (Doctrine renders links-only —
no `data` member; the in-memory witness, having no load-state predicate, renders the
**full unfiltered** set — never the filtered singleton, so no leak either way) and emits
no window pagination link; including the relation re-enables the window and the filter
applies (existing behaviour preserved); a `withData()` to-many is still windowed when
not included (its data renders, so the filter must apply); and a filtered `?withCount`
on a not-included to-many still reports the filtered total. A Doctrine query-budget
witness asserts the not-included filtered lazy to-many issues **zero** window queries
(no `featured_article_id IN (…)` scope), where it ran one per such relation before.

## Follow-up: the windowed page is supplied out-of-band (no parent write)

The rendered-data gate above stops a *not-rendered* relation being windowed, but the
window still **wrote** each *windowed* relation's page 1 back onto the parent's relation
property — and that write itself leaks onto any **sibling relation sharing the backing
column**. Empirically (both providers, existing fixtures): `comments` (`withData`,
windowed) and `lockedComments` (lazy, `storedAs('comments')`, neither addressed nor
included) share one column; filtering `comments` to comment 1 wrote `[1]` onto the
`comments` property, so `lockedComments` rendered `[1]` too — and on Doctrine the write
flipped its load-state to "loaded", so it emitted a `data` member the lazy default omits.
A restore-the-bystander-column fix is impossible here: one column cannot hold both the
windowed filtered page (for `comments`) and the bystander's full set (for
`lockedComments`), because core reads BOTH off the same property.

The fix routes the windowed page to core **out-of-band** through the new core
relationship-linkage seam (core ADR 0083), so the batcher **never writes the page onto
the parent**. `RelationshipWindowBatcher` builds a `WindowedRelationshipLinkage`
(`spl_object_id(parent) → [relation → RelationshipLinkage]`, the page-1 items wrapped in
the column's container) alongside the existing `WindowedRelationshipPagination`, pairs
them in a `WindowedRelationshipResult`, and the handler swaps both into their
request-scoped holders (`RequestScopedRelationshipLinkage` mirrors the pagination holder,
`kernel.reset`-tagged). Core's `buildToMany()` consults the linkage seam first: a windowed
relation renders its supplied page; every other relation — a column-sharing bystander
included — reads its **own untouched** property. So a relatedQuery filter on one
shared-column relation narrows ONLY that relation; the sibling renders its true membership
(Doctrine: still links-only — the property was never written, so the load-state stays "not
loaded"; in-memory: the full set). Dual-provider conformance
(`windowingOneSharedColumnRelationDoesNotAlterASiblingBystandersLinkage`) asserts exactly
this, and the whole suite stays green on both kernels.
