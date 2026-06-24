# The to-many relationship (linkage) endpoint is a queryable, paginated collection

`GET /{type}/{id}/relationships/{rel}` for a to-many rendered the **whole
association** off the parent property and honoured no query parameters — a requested
`filter` was even rejected as a `400` (the interim bundle #70, since reverted as the
wrong call). Its related-resource twin `GET /{type}/{id}/{rel}` is a real queryable,
paginated collection (ADR 0031), so the two endpoints diverged for no principled
reason: a client could page/filter/sort the related *resources* but not the *linkage*.

The decision: a **monomorphic, non-pivot** to-many relationship endpoint is a real
queryable, paginated linkage collection at **full parity** with the related endpoint —
`?filter`/`?sort` scope against the same merged vocabulary (the related resource's
filters/sorts ⊕ the relation's own scoped ones) and **pagination is on by default**
(the relation → related resource → server-default chain). `CrudOperationHandler::
fetchRelationship`'s to-many tail mirrors `fetchRelated`'s fetch exactly — the shared
`RelationCriteriaFactory` (`paginatorFor` + `criteriaFor`), `validateFilterValues`,
`DataProvider::fetchRelatedCollection`, and `preloadIncludes` — so an unknown
filter/sort key is the endpoint's same `400` and the count-free-by-default semantics
(G21) are reused verbatim.

The page-1 linkage and its pagination are supplied to core **out-of-band** through the
relationship-linkage / relationship-pagination seams (core ADR 0083 / 0058), reusing
the `WindowedRelationshipLinkage` / `WindowedRelationshipPagination` map delegates the
Relationship Queries profile already builds (here single-entry maps keyed by the one
rendered parent + relation). There is **no destructive write** onto the parent's
relation property — the same reason the include-window batcher went out-of-band (ADR
0086): a write would corrupt a sibling relation sharing the backing column. Core's
`buildToMany()` then renders the supplied page as the linkage `data` and emits the
relationship object's `first`/`prev`/`next`(/`last`) links in the spec's plain form
against the relationship-linkage endpoint — pagination lives in the relationship's own
`links`, not a document `meta.page`; the convention `self` stays the bare endpoint URL.

A **pivot** (`belongsToMany`) to-many is queryable too: its relationship endpoint
windows the pivot collection (`fetchRelatedPivotCollection`, the windowed twin of the
whole-association map), supplies the page-1 linkage + pagination through the same seams,
and renders each member's `meta.pivot` through a `PivotParentSerializer` over the
WINDOWED pivot map — the two compose because `PivotSubstitutingResolver` delegates the
linkage/pagination seams to the Server. A **polymorphic** (`MorphToMany`) to-many's
members span types — no single related provider or shared vocabulary to window mixed
linkage through — so querying it is a fast-follow: a requested `filter`/`sort`/`page` on
its relationship endpoint is rejected with the related endpoint's same `400` (rather than
silently ignored), and the projector advertises no query parameters for it (core ADR
0096 gates the param projection on a monomorphic relation).

The in-memory provider's `fetchRelatedCollection` now treats a null/absent to-many as
`[]` (via `asIterator()`, mirroring `countRelated`) so routing every to-many
relationship GET through the fetch path no longer asserts on an unset association. The
OpenAPI projector projects the merged `filter[]`/`sort`/`page` parameters on a
**monomorphic** to-many relationship GET (pivot included; polymorphic excluded); the
linkage-document response schema already permits arbitrary further links, so no schema
change was needed. Dual-provider conformance
(`RelationshipCollectionParamsConformanceTestCase`): filter/sort/page applied to the
linkage on both the in-memory and Doctrine-sqlite kernels, the relationship-object
pagination links, the countable `?withCount=_self_` gate, and the unknown-key `400` — the
linkage twin of `RelatedCollectionParamsConformanceTestCase`; the pivot windowing +
`meta.pivot` and the polymorphic `400` are witnessed by the example `PivotTest` /
`PolymorphicRelationTest`.
