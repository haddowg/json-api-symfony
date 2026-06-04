# Collection fetches are criteria-driven: the handler resolves, a shared applier matches, providers only execute

Letting each `DataProvider` interpret the raw query parameters itself would
duplicate the spec semantics — which `filter[…]`/`sort` keys are declared,
unknown key → 400, `-` prefix → descending — per store, and divergence there is
exactly what the in-memory conformance witness (ADR 0004) exists to catch. The
read handler instead resolves everything declaration-shaped up front
(`filters()`, `allSorts()`, `pagination()` from the resource) into a
`CollectionCriteria`; the shared `CriteriaApplier` does the matching and raises
the spec errors once for all providers; a provider supplies only its execution
— core `FilterHandlerInterface`/`SortHandlerInterface` implementations and a
windowed materialization. A provider failing a spec test therefore differs from
its peers only in execution, never in interpretation.

Pagination is push-down: core's `PaginatorInterface::window()` (core ADR 0015,
forced by this phase) yields the fetch window before any items are
materialized, the provider returns the windowed items plus the pre-window
total, and the handler builds the page via `paginate()` /
`DataResponse::fromPage()`. Providers narrow on the window value object's
concrete type (`OffsetWindow` today), leaving room for a cursor-shaped window
without reshaping the SPI.
