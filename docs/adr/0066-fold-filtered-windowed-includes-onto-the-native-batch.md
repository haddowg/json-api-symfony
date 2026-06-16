# Fold filtered windowed includes onto the native batch

ADR 0065 ran a FILTERED windowed include through a per-parent bounded fallback (M
real-`LIMIT` queries) while only an unfiltered one took the bounded native
ROW_NUMBER batch — a pragmatic split that kept the native path off the filter
vocabulary at the cost of M queries for the filtered case. We have now folded the
filtered case onto the SAME native path so a windowed include — filtered or not —
runs in ONE bounded query on the default `on` setting, WITHOUT a native filter
translator: the inner scoped query is built as a normal Doctrine DQL `QueryBuilder`
(the RelationScope-style parent-scope plus the shared `CriteriaApplier` driving the
#1 DQL `DoctrineFilterHandler` — the exact executor the related-collection endpoint
and the in-memory witness already mirror), its SQL is read via `Query::getSQL()`,
and that SQL is wrapped with `ROW_NUMBER()/COUNT(*) OVER`. So the filtered native
path emits byte-identical WHERE predicates to the endpoint — witness-equivalence is
free, because it IS the same executor, just wrapped for the window.

The wrap references the inner query's GENERATED SQL column aliases (the parent
discriminator, the sort columns, the pk), which are READ off the `ResultSetMapping`
Doctrine built for the query, never predicted: the sort/pk entity-field aliases via
the public `ResultSetMapping::getColumnAliasByField()`, the scalar projections (the
`IDENTITY()` parent discriminator, the join-table pair ids, the projected sort
scalars) by reverse lookup on the public `scalarMappings` map. The inner DQL carries
no `ORDER BY` (the sort is not applied to it — the outer window re-orders); the
window `ORDER BY` is emitted from the read aliases with the same portable
NULL-ordering `CASE` term and PK tiebreak ADR 0065 established, so ties stay
identical to the witness. `getSQL()` emits POSITIONAL `?` placeholders, so the inner
DQL parameters are rebound onto the `NativeQuery` by ORDINAL at the SQL positions
`ParserResult::getParameterMappings()` reports (each carrying its DBAL type, so an
`ArrayParameterType` IN-list and a `STRING` LIKE bind correctly), with the row cap at
the next ordinal. The two shapes mirror ADR 0065: the inverse-FK inner query roots on
the related entity (hydrated inline); the join-table / many-to-many inner query roots
on the parent and selects the scalar `(parentId, relatedId)` pairs plus the sort
columns as scalars (DQL cannot select a non-root joined entity), then id-loads the
distinct related entities — one further query.

The one non-public seam: Doctrine exposes no public accessor for the RSM or the
parameter mappings of a `Query` (`Query::getResultSetMapping()` is `protected` at
every layer — only `setResultSetMapping()` is public — and `Query::parse()` is
`private`), and `ResultSetMappingBuilder` generates FRESH aliases rather than the
inner query's, so it cannot read the real generated `id_0`/`body_1`/`sclr_2` the
wrapped SQL must reference. We therefore reflect the protected
`getResultSetMapping()` and the private `parse()`; both reuse the SAME cached parse
`getSQL()` already triggered (no double parse, no extra query). The alias-READING
methods are public API; only the two accessors are reflected. Pinned to the locked
doctrine/orm 3.x line and called out here.

The per-parent bounded fallback is RETIRED from the `on` path for the filtered case —
it now serves only `window_functions: off` and an EXTENDED related type (a
soft-delete / tenant / published-only extension whose DQL `WHERE` the batched native
shape does not thread; folding extensions onto the native path is out of scope of
this slice). Functional acceptance is unchanged in shape — the filtered windowed
include conformance case now passes via the native path on the `on` kernel, plus a
budget probe proves the filtered include runs ONE bounded native ROW_NUMBER query
(carrying the DQL filter handler's `LOWER(...) LIKE ... ESCAPE` predicate inside the
wrapped inner query, bounded `jsonapi_rn <= :limit`, with `COUNT(*) OVER` the real
filtered per-parent total) and NOT M per-parent queries. This is a
Doctrine-provider-internal slice: no core change.
