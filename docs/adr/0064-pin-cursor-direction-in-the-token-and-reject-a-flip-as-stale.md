# Pin the per-column sort direction in the cursor token and reject a flip as stale

The `CursorTokenMinter` now encodes the resolved keyset's per-column directions
into each boundary token (the third `CursorBoundary::$descending` arg core added,
keyed identically to the values — every keyset column incl. the appended PK), and
the keyset stale check (`KeysetResolver::assertFresh`) compares them: a request
whose resolved active sort flips a column's direction (`?sort=name` →
`?sort=-name`) while holding a cursor is now a `StaleCursor` (400), even when the
column SET is unchanged.

A column-set comparison alone (the prior check) could not catch a same-columns
direction flip — the cursor would be silently reused under the opposite order,
skipping or repeating rows. Pinning the direction the token was minted under is
free (cursors are opaque + ephemeral, so a token lacking the directions key is a
`MalformedCursor` upstream with no back-compat needed pre-v1) and the change is
shared by both providers, since each mints through the one `CursorTokenMinter`
and stale-checks through the one `KeysetResolver`.
