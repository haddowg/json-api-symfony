# Cursor-resolved includes collapse to one ROW_NUMBER window per relation

- **Status:** accepted — supersedes the per-parent mechanism of [ADR 0116](0116-client-selectable-pagination-carries-a-page-schema-and-resolves-up-front.md)

ADR 0116 lifted the throw on a cursor-resolved **included** relation by minting a first
cursor page **per parent** — routing a boundaryless `CursorWindow` through the same
per-parent `fetchRelatedCollection` keyset fetch (`runCursor`) once for each parent on the
page (N keyset `LIMIT` queries per relation). It deferred the single-query collapse the
offset windowed include already had (bundle ADR 0065/0066) because of an *unverified* fear:
that the keyset's portable NULL=largest `ORDER BY` term — `CASE WHEN c IS NULL THEN 1 ELSE 0
END <dir>` — might not compose correctly, or might carry bindings, once folded **inside** a
`ROW_NUMBER() OVER (… ORDER BY …)` window.

That blocker is now **disproven by construction**. It is the *same* raw SQL term the offset
ROW_NUMBER window (`WindowedRelationBatch::orderBy`) has emitted since ADR 0065, spliced
verbatim into the `OVER` clause over the generated sort-column alias read off the inner
query's `ResultSetMapping`, carrying **no** bindings. So `DoctrineDataProvider::
fetchRelatedCollectionBatch` now routes a boundaryless `CursorWindow` to
`WindowedRelationBatch::fetchCursor` — the SAME derived-table `SELECT *, ROW_NUMBER() OVER
(PARTITION BY <parent discriminator> ORDER BY <keyset>) … WHERE jsonapi_rn <= ?` the offset
path builds, wrapping the SAME inner scoped+filtered DQL, over both inner-query shapes
(inverse-FK and the join-table / many-to-many scalar-pair id-load). The **N→1** collapse: a
collection include of one cursor relation over M parents is one bounded native statement, not
M keyset queries. It is gated **identically** to the offset path (window functions on **and**
no query extension on the related type).

Two things differ from the offset window, both mechanical: the `ORDER BY` is driven by the
resolved `KeysetColumn`s (from the ONE core `KeysetResolver` the per-parent path and the
in-memory witness use) — the forced NULL=largest `CASE` term per column and the **deduped PK
tiebreak carrying the last active directive's direction**, never a hardcoded `id ASC` — and
there is **no** `COUNT(*) OVER` (a cursor page is count-free by definition, so the window
probes `jsonapi_rn <= limit + 1` for the `hasMore` signal). There is **no** keyset `WHERE`:
an include is a boundaryless first page, so a bounded page (an `after`/`before` boundary)
routes to the per-parent fallback, which owns `DoctrineKeyset::applyAfter`. Each partition's
boundary rows mint a forward cursor through the SAME `CursorTokenMinter` + row-value reader
(`ClassMetadata::getFieldValue`) the per-parent path uses, so the single window is
byte-identical to the per-parent minting.

The per-parent loop (`fetchWindowedBatchPerParent`) is **retained** as the fallback for a
relation the native window cannot express (polymorphic, `window_functions: off`, or an
extended related type) and remains the related-collection **endpoint**'s path. The in-memory
witness keeps windowing each parent per-parent and is the **parity referee**:
`CursorIncludeBatchConformanceTestCase` asserts the SQL push-down and the PHP witness render
byte-identical pages — now including a collection include sorted on a **nullable** column
with the null bucket interleaved across parents and mixed surplus (one partition over-fills
page 1 → a `next`, one fits it exactly → no `next`), the exact NULL-inside-`ROW_NUMBER`
composition the blocker feared — while `DoctrineCursorIncludeBatchBudgetTest` pins the
single bounded ROW_NUMBER statement. This is the Doctrine twin of the Laravel adapter's
**ADR 0026** (the Eloquent `groupLimit` cursor-include collapse).
