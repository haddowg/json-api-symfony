# The pair-shape include hydrates through the extensions as `IncludeHydration`

A many-to-many or owning-side to-many include selects (parent, related) id pairs under
the related type's extensions, then loaded the related entities with a bare
`WHERE id IN (…)` that ran no extension, so an extension could not fetch-join what the
included rows render and each row lazy-loaded it. The id load now runs the extensions
under a new `QueryPurpose::IncludeHydration`. Its ids are already scoped, so it is the
one purpose an extension may opt in to: a filtering extension that applies again
changes nothing, and a fetch-join opts in here because the pair query's scope runs on
a single-column id subquery a join would break. We rejected reusing
`FetchRelatedCollection`, since an extension could then not tell a scope it must apply
from a hydration it may shape. The to-one include's id load keeps
`FetchRelatedCollection` because its ids come off the parents unscoped and that load
is the scope. The native windowed include batch also loads by id, but it only runs
when the related type has no extension, so it needs nothing.
