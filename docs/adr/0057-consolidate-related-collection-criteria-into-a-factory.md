# Consolidate related-collection criteria into a `RelationCriteriaFactory`

The related-collection endpoint (`CrudOperationHandler::fetchRelated()`) and its
include/linkage twin (`RelationshipWindowBatcher`) had each duplicated the same
related-to-many query assembly: the 3-tier per-relation paginator chain
(`relation -> related resource -> server default`), the resource⊕relation filter/sort
vocabulary merge (key-dedup, relation wins, core ADR 0051) — with the endpoint adding
a third pivot-fields layer — and the `CollectionCriteria` assembly, including a
byte-identical private `mergeFilters`/`mergeSorts` pair in both classes. We extracted
a stateless `RelationCriteriaFactory` (`paginatorFor()` + `criteriaFor()`,
`includePivotFields` toggling the pivot layer) that owns this logic once and routed
both call sites through it, so the merge order, paginator resolution, default sort,
and the validated filter vocabulary stay identical while the duplication is gone — a
pure behaviour-preserving refactor that also gives the later filtered-`?withCount`
and query-engine consolidation steps a single seam to build on. The primary-collection
path keeps its different 2-tier (relation-free) chain and is untouched.
