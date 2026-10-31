# Cursor pagination executes as a keyset window over a forced NULL-largest order

A `CursorPaginator` pages by keyset on both providers, for primary, related, pivot-related and relationship collections: the active sort (else the default sort) plus the primary key as the final tiebreak gives a total order, and NULLs sort largest portably through a leading `CASE WHEN c IS NULL` term, since MySQL and SQLite lack `NULLS LAST`. The in-memory provider is the ground truth, with its own NULL-largest comparator (core's array sort orders NULL smallest), and each token pins the per-column directions it was minted under, so reusing a cursor after a direction flip is a stale-cursor `400` rather than skipped or repeated rows.

## Consequences

Boundary values bind with the column's Doctrine type, because a string-bound datetime would compare lexically. Cursor pages are count-free.
