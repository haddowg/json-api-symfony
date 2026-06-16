# Extract related-collection parent-scoping into a `RelationScope` primitive

`DoctrineDataProvider::fetchRelatedCollection()` inlined the parent-membership
scoping of a related-to-many query — the single-valued inverse-FK fast-path, the
`IN`-subquery branch for owning-side/many-to-many relations, and the
`inverseOwningField` resolver behind the branch selection. We extracted that
logic verbatim into a stateless `RelationScope` primitive
(`scopeToParent(QueryBuilder, rootAlias, relatedClass, parent, relation)`) and
routed the fetch through it, because the upcoming related-count/batch step needs
the *same* parent-scoping predicate against a different query shape — one seam
keeps the two byte-identical (the branch selection, WHERE clause(s), subquery
DQL, and `:jsonapi_parent` binding are unchanged). This is a pure
behaviour-preserving refactor: the polymorphic precondition guard, the extension
loop, the criteria applier, and the window executor tail stay in the fetch
untouched, and the SQL/param-binding is the same. The pivot (`belongsToMany`
association-entity) fetch keeps its own `pivot`-alias scoping for now — a
different shape that is reconciled in the next step.
