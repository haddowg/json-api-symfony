# Lazy relationship-data default consumed by the load-state seam (the Finding-2 N+1 fix)

The pre-v1 query-budget audit (bundle ADR 0072, task #45) surfaced that a plain
collection read forced **one lazy load per parent for every to-many relation** whose
linkage `data` rendered — the Doctrine `PersistentCollection` initialised purely to
serialize identifiers (an M×K N+1). Core ADR 0067 fixes this at the root by flipping
the relationship-data default to **lazy per relation type** (a to-many and `HasOne`
default links-only-until-loaded; `BelongsTo`/`MorphTo` stay eager since their id is on
the owner), replacing the opt-in `dataOnlyWhenLoaded()` with its inverse `withData()`.
The bundle consumes the flip: the reference `DoctrineRelationshipLoadState` predicate
(bundle ADR 0015) is unchanged but now serves the lazy *default*, so a non-included
to-many on a managed entity renders its links and **never initialises its collection**
— witnessed by a new query-budget regression (`DoctrineReadQueryBudgetTest`) asserting a
collection read issues zero per-parent linkage loads for the lazy `pinnedComments`
relation. The in-memory provider wires no predicate, so its relations are always
"loaded" and emit data as before; the dual-provider conformance keeps an eager
`withData()` to-many as the always-emitting baseline alongside the lazy witnesses. Call
sites that previously declared `dataOnlyWhenLoaded()` (test/example resources) drop it —
a to-many is now lazy without it — and the deliberate eager demonstrators (the example
track→playlists relation, the conformance `comments` baseline) adopt `withData()`.
