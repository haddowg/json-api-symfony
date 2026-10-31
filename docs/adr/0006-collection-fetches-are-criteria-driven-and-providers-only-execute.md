# Collection fetches are criteria-driven: the handler resolves, a shared applier matches, providers only execute

The handler resolves a request's filters, sorts and pagination against the resource's declared vocabulary into a `CollectionCriteria`, and the shared `CriteriaApplier` matches keys, raises the spec errors and folds in core's filter defaults, so a provider supplies only execution: core filter and sort handlers plus a window. Letting each provider interpret raw query parameters would duplicate the spec semantics per store, which is exactly the divergence the in-memory witness exists to catch.

## Consequences

Pagination is pushed down: the paginator's window reaches the provider before anything is materialised, and the branch logic (whole set, counted page, count-free probe) is core's `WindowExecutor`, so a provider supplies only the store-specific slice and count. Defaults are folded before the count, so totals describe the defaulted collection.
