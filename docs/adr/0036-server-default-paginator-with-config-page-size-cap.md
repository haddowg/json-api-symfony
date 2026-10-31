# The bundle ships the safety bounds core leaves open: a page-size cap and an include-depth cap

Core is deliberately unopinionated (no default paginator, unlimited include depth). The bundle installs a server-default `PagePaginator` whose client-controlled `page[size]` is clamped to `json_api.pagination.max_per_page` (default `100`), and a `json_api.max_include_depth` of `3`, so every collection that falls through to the server default is paginated and a mutual default-include cycle terminates with no configuration. Keeping the unpaginated baseline was rejected because an uncapped collection and an unbounded `page[size]` are the larger denial-of-service surface.

## Consequences

A resource's or relation's own pagination and depth still win. A custom default paginator, for every server or for one, replaces the built-in one and owns its own ceiling, and `0` disables either built-in bound.
