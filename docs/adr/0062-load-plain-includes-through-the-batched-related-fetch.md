# Load plain `?include` through the batched related fetch (drop ShipMonk)

Plain (unwindowed) `?include` loading was a **Doctrine-only** path: a
`PreloadsIncludesInterface` capability the `DoctrineDataProvider` implemented by
delegating to an `IncludePreloader` that wrapped `shipmonk/doctrine-entity-preloader`.
The in-memory witness never implemented it, so its includes were always lazy, and the
mechanism was an external library coupled to one provider. ADR 0061 had already added
the storage-agnostic `DataProvider::fetchRelatedCollectionBatch()` seam (for the
*windowed* include path). We now route **plain includes through that same seam** and
**drop ShipMonk entirely**, so the batch IS the single include-loading mechanism — the
dissolve principle: a relation/provider that cannot batch falls back to LAZY exactly as
before, and the opt-out moves into *how a provider implements the batch*.

**Fast-path mode.** A plain include builds a `CollectionCriteria` with empty
filters/sorts and a **null window**, so the batch loads the WHOLE related set per
parent (`WHERE fk IN`, no per-parent slice — `WindowExecutor`'s null-window branch
returns `new CollectionResult(all())`: total null, windowed false). That is
**byte-for-byte** the rows the preloader/ShipMonk loaded, in the same number of queries
(inverse-FK `OneToMany` → 1; owning-side/m2m → the pair shape's 2), so over-fetch and
budget are **unchanged**.

**To-one include arm (net-new).** ADR 0061's batch was to-many only; a plain include
tree includes to-one relations (`tracks.album`). The batch grew a to-one arm: each
parent's result is a 0-or-1 `CollectionResult`. Doctrine reads each parent's target id
**off the already-managed parent** (a proxy exposes its identifier WITHOUT
initialising it — no extra round-trip), loads the distinct targets in ONE
`WHERE id IN (:ids)` query, and partitions 1:1 — ShipMonk's documented to-one preload
shape, ONE query for the level, so the nested include budget (4 for `tracks.album`) is
preserved. The in-memory witness wraps each parent's `readValue()` object. The
orchestrator writes back `items[0] ?? null` onto the to-one column — **never** an
array/`CollectionResult`, which would corrupt the render.

**The orchestrator: `RelatedIncludeBatcher` (successor to `IncludePreloader`).** A
**provider-agnostic** bundle service (`DataProviderRegistry` + `TypeMetadataResolver` +
`ServerProvider`, not `EntityManager`) that lifts the include-decision
(`isIncludedRelationship` + the default-include fallback) and the three ADR-0037
safeguards (max depth, allowed paths, cyclic-default terminator) **unchanged** from the
old preloader, walks the effective include tree level by level, calls
`fetchRelatedCollectionBatch` per included relation over the level's parents, writes
each result back via `Accessor::set`, and recurses with the loaded targets — threading
the root-resolved safeguards through. Because it drives whatever provider serves each
level's type, it batches includes for the **in-memory** provider too (an idempotent
re-assignment of the same objects, changing no rendered bytes — strictly better than
the always-lazy status quo).

**Skip → lazy boundaries.** A **polymorphic** relation (more than one related type) is
skipped in the orchestrator (so even the in-memory provider, which CAN read a mixed
set, is left lazy — keeping includes byte-identical with the Doctrine boundary). A
**computed/`extractUsing`** column that is not a real association, or a **composite-id**
target, is detected INSIDE the Doctrine batch (the retired preloader's
`hasAssociation`/composite-id guards moved into the provider), which returns an empty
`RelatedBatch` so the write-back no-ops and the relation renders lazily. A related type
with no batching provider is skipped. Every one is the same lazy fallback as before.

**Dissolved + dropped.** `PreloadsIncludesInterface` and `IncludePreloader` are deleted;
the handler's `preloadIncludes()` helper routes unconditionally through the injected
`RelatedIncludeBatcher`. `shipmonk/doctrine-entity-preloader` is removed from
`composer.json` (require-dev + suggest), the example app, the DI wiring, and its
PHPStan stub. The runtime disable seam moves from `DoctrineDataProvider::disableIncludePreloading()`
onto `RelatedIncludeBatcher::disable()` (process-wide rather than per-type — the
conformance witness only ever disabled one type, so behaviour is equivalent), preserving
the byte-identical and N+1 proofs with no interface.

**Trade-off (accepted): `addFetchJoins` dropped.** ShipMonk auto-fetch-joined an inverse
to-one to save a round-trip; Approach B does a separate id-IN. It was never a
correctness property and changes no rendered document — re-addable later if a budget
probe flags it. Witnesses are mechanism-agnostic (budget-shaped + byte-identical), so
the `IncludePreloadTest` re-points onto the batch with the same bounds (3 single-level,
4 nested), and the include-safeguards conformance suite stays green on both providers.
No core change.
