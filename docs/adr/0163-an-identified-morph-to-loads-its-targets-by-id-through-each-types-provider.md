# An identified MorphTo loads its targets by id through each type's provider, and loads for linkage only the guarded types

A `MorphTo` declared with `identifiedBy()` (core ADR 0189) has no association to batch, so the bundle groups a page's stored pairs by type and asks each type's provider for those ids in one call, through a new optional `FetchesByIdsInterface`, falling back to `fetchOne()` per id. The Doctrine provider answers with one `WHERE id IN (...)` under the type's extensions as `QueryPurpose::FetchRelatedCollection`, since the ids come off the parents unscoped and this load is the scope, as for the to-one include (ADR 0164). An include or the related endpoint loads every type. A linkage alone, in a document or on the relationship endpoint, loads only the guarded types, so a hidden or missing target of a guarded type renders as null like any guarded to-one, and an unguarded type keeps its zero-load pair. A type is guarded on a server when the relation declares a read permission, the type declares a read gate there, or its provider may hide a row: `scopesFetchByIds()` for a provider with the interface, `scopesRelatedReads()` otherwise, and any provider without either.

## Considered options

- Never scope a linkage that isn't included. It kept every pair free but let a pair naming a hidden row render, which a guarded `BelongsTo` never does.
- Always load the targets. That costs a query per type on every page even where nothing can be hidden, which is the case `identifiedBy()` exists for.
- Compute the guarded types at container build. The provider answers come from the provider instances, so they are computed on first use and cached per server and type; only a provider without the interface, whose answer can depend on the relation, is asked each time.
