# A pivot relation reads a page of parents in one grouped query

The Doctrine provider read a pivot `belongsToMany` once per parent, so an include, a `withData()` linkage or a `relatedQuery` over a page of 25 playlists ran 25 pivot queries. It now runs the single-parent pivot query once for the whole page: the far entity stays the root, the association entity is joined and scoped to the page's parents, and the rows are grouped by parent and far member, so each parent gets the members, order and pivot values its own query would give. A windowed include wraps that query in the per-parent `ROW_NUMBER` window the other to-many includes use, with the per-parent total from `COUNT(*) OVER` when the page is counted.

## Considered Options

- Selecting scalars only and loading the far entities by id in a second query, as the many-to-many batch does: it works, but costs a query the single-parent read never ran. The batch selects the far entity and hydrates the rows one at a time instead, which keeps a member two parents share on both parents' rows.
- Rooting the query on the association entity: the related type's filters, sorts and query extensions all read the query root, so rooting on the far entity applies them exactly as the related endpoint does.

## Consequences

- The window orders by bare columns, not the NULL-last `CASE` terms of the other windowed arms, so NULLs sort where the engine puts them, as they do in the single-parent query.
- A windowed include of a related type with a query extension, with `window_functions: false`, with a sort arm or a cursor window still reads one parent at a time, like the other windowed arms. An unwindowed read always batches, extensions included.
- The rows hydrate one at a time through a native query built from the DQL's own SQL and result set mapping, because Doctrine's `toIterable()` refuses a join to a to-many association anywhere in the query, which an existence filter's subquery has. That reads the same internal `Query` methods the windowed includes already read, and a Doctrine release without them falls back to the per-parent read.
