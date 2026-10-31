# Windowed includes run as one native ROW_NUMBER query wrapping the DQL-built query

A windowed include (a page of each parent's relation) runs one bounded native statement per relation: the inner query is the same DQL the related endpoint runs (parent scope, filters through the shared applier), and its SQL is wrapped in `ROW_NUMBER()` and `COUNT(*) OVER (PARTITION BY parent)`, so each parent's slice and real total come from the database. The wrapper must reference the inner query's generated column aliases and parameter positions, which Doctrine exposes only through the protected `Query::getResultSetMapping()` and the private `parse()`, so the bundle reaches them by reflection, guarded by a check that they exist.

## Considered Options

- Translate the filter vocabulary into a native SQL handler: it forks the single conformance witness into two executors and re-solves portability the DQL path already handles.
- Predict Doctrine's generated aliases: version-fragile.
- Materialise each parent's whole related set and slice in PHP: unbounded over-fetch for large relations.

## Consequences

`json_api.doctrine.window_functions` (default `true`, with no auto-detection) turns it off for engines without window functions. That setting, a query extension on the related type, and polymorphic relations use a per-parent bounded fallback. A cursor-paginated include uses the same window with the keyset order and a `limit + 1` probe.
