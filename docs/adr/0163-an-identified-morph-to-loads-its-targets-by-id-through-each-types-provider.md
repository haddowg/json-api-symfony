# An identified MorphTo loads its targets by id through each type's provider

A `MorphTo` declared with `identifiedBy()` (core ADR 0189) has no association to batch, so an include or its related endpoint groups the parents' stored pairs by type and asks each type's provider for those ids in one call, through a new optional `FetchesByIdsInterface`, falling back to `fetchOne()` per id. The Doctrine provider answers with one `WHERE id IN (...)` under the type's extensions as `QueryPurpose::FetchRelatedCollection`, since the ids come off the parents unscoped and this load is the scope, as for the to-one include (ADR 0162). The loaded rows reach core through the linkage seam, so a row the scope hides renders as none, and the per-row read gate checks the loaded row; with nothing loaded the seam answers `null` and the pair renders. So a linkage that isn't included is the pair as stored, and neither the scope nor the read gate sees it.

## Considered options

- Scope a linkage that isn't included, as a scoped `BelongsTo` does. That costs the target load per page that `identifiedBy()` exists to avoid, so linkage outside an include is the stored pair as is, and an author who needs the scope to decide includes the relation.
- Reuse `admitRows()` or `fetchRelatedCollectionBatch()`. Both need the target objects or an association to start from, and the pair has neither.
