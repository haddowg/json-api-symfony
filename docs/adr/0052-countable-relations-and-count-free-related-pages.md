# Pagination is count-free unless a count is asked for, and relation counts batch per page

A `COUNT` runs only when the paginator opts in (`withCount()`) or a client asks with `?withCount` under the negotiated Countable profile, on a `countable()` resource or relation; otherwise a page has no total and no `last` link, and `next` comes from a `limit + 1` probe. One count feeds both `meta.total` and `meta.page.total`. A relation count goes through `DataProviderInterface::countRelated()` once per relation for the whole page of parents and honours the relation's active filters and defaults, so it always equals the related endpoint's total.

## Consequences

A pivot relation counts distinct far members. Under strict query parameters a `?withCount` without the Countable profile is an unrecognised parameter. The request-scoped count holder is reset between requests so counts never leak in a long-running worker.
