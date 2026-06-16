# Execute cursor pagination as a keyset window over a forced NULL=largest order

A resource (or server) that returns a `CursorPaginator` from `pagination()` now
pages via a real **keyset (seek) window** on both providers, consuming the core
C1 cursor scaffolding (`CursorWindow`/`CursorBoundary`/`CursorCodec`/
`CursorPaginator::fromBoundaries`/`CursorBasedPage`/`CursorCollectionResult`/
`WindowExecutor::runCursor`/`StaleCursor`/`MalformedCursor`) with **no further
core change**. The handler already resolves the paginator and calls
`window()`, so a `CursorWindow` lands on the `CollectionCriteria`; each provider
narrows it in `fetchCollection` and runs the keyset push-down, and the handler
renders the minted boundary tokens through `CursorPaginator::fromBoundaries`.
The offset/page (`OffsetWindow`) and no-window paths stay byte-identical — the
cursor branch is the only total-null primary collection path.

The keyset columns are resolved **once** (a shared `KeysetResolver`): the active
sort (each `SortByField` → its column, validated against the declared vocabulary
exactly as the plain path — an unknown key 400s, a computed/non-`SortByField`
directive is `UnsupportedSort`), falling back to the resource `defaultSort()`,
then the **primary key appended** as the final column (deduped when the client
already sorts by it; its direction follows the last active directive so the order
stays monotone). Because the id is non-null, the final level is the plain
`id >/< :v` tiebreak that closes a total order even when every sort column ties —
so a non-unique sort column never skips or repeats a row across pages.

NULLs are forced **largest, portably** (not `NULLS LAST`, which MySQL/SQLite
lack): each column emits a leading `CASE WHEN c IS NULL THEN 1 ELSE 0 END`
0/1 term then the column, both in the column's direction — every engine orders
the 0/1 identically. The keyset `WHERE` is the lexicographic indicator of
"strictly after the boundary under that order": an `orX` of levels, each pinning
the higher-significance columns to the boundary with a **null-aware** equality
(`IS NULL`, never `= :v` which is UNKNOWN and would drop the row) and requiring
column i strictly after on its own via the four-case AFTER matrix
(`asc+v` → `c > :v OR c IS NULL`; `asc+null` → contributes nothing — the tie is
carried by later levels' `IS NULL` prefix; `desc+v` → `c < :v`; `desc+null` →
`c IS NOT NULL`). A backward (`page[before]`, which wins over `page[after]`) page
flips every direction (flipping the null bucket via the leading `CASE` term) and
the AFTER operators, over-fetches `limit + 1`, slices, then `array_reverse`s to
natural forward order before minting. Boundary values mint as JSON-safe scalars
(a date → RFC3339 with microseconds, a `Stringable` id → its string) and bind
back **typed**: the Doctrine keyset coerces a datetime wire string to
`\DateTimeImmutable` and binds with the column's DBAL type (a string-bound
datetime would compare lexically wrong), each occurrence on a fresh
`jsonapi_cursor_N` placeholder distinct from the filter handler's namespace.

The **in-memory provider is the ground truth** and the Doctrine push-down must
match it byte-for-byte: it implements the same forced order with its OWN
NULL=largest comparator (deliberately **not** core's `ArraySortHandler`, whose
`<=>` orders NULL smallest — the opposite) and the same AFTER predicate over
`Accessor::get` values, coercing a row value to the same wire form the minter
uses so it compares like-for-like with what it minted. Both providers derive the
order from the single `KeysetResolver`, mint through the same `CursorCodec`, and
run the stale check there (only the provider owns the active-sort → keyset-column
resolution), so a divergence localizes to a provider's keyset execution. A
`CursorConformanceTestCase` asserts identical results on both kernels over a
dedicated `cursorWidgets` type (a tie-bearing `category`, a nullable `priority`,
a nullable datetime `releasedAt`): forward/backward round-trips, mixed asc/desc,
the PK-only and PK-tiebreak cases, the nullable null-bucket walk, exhaustion,
before-wins-over-after, the typed date boundary, and the stale/malformed 400s.
