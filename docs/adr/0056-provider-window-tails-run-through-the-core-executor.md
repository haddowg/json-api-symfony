# Provider window/count tails run through the core WindowExecutor

Both read providers each carried their own copy of the collection window/count/count-free
"tail" — decide from the requested window and whether the collection is countable what
to fetch (the whole filtered set, a counted page, or a count-free `limit + 1` probe) and
shape the result. Step 1 of the query-building consolidation moved that branch logic into
a single core `haddowg\JsonApi\Collection\WindowExecutor` (core ADR 0061), so we route
`DoctrineDataProvider::fetchCollection`/`fetchRelatedCollection` and
`InMemoryDataProvider::applyAndWindow` through `WindowExecutor::run()` — each provider
supplies only the store-specific closures (`QueryBuilder` `LIMIT`/`OFFSET`/`COUNT` for
Doctrine, `array_slice`/`count` for in-memory) and gets the identical, single-sourced
branch logic, deleting the hand-rolled `countFreePage` and the in-memory inline branch.
The result value object `CollectionResult` moved to core in the same step, so the bundle
imports the core FQCN and `PivotCollectionResult` now extends it.

## Consequences

The pivot tail (`fetchRelatedPivotCollection`) is the one site left hand-rolled: it
windows over Doctrine "mixed" rows (`[0 => farEntity, 'pivot_<field>' => value]`), and the
far-entity windowing cannot be separated from the per-member pivot map — they ride the same
grouped query — but the executor is generic over *object* entities and cannot carry the
array-shaped rows. It mirrors the executor's branches in-place (same count-free probe /
countable count), behaviour-identical, until the pivot row shape is reconciled with the
executor's entity contract. This is a pure refactor — the rendered documents and the
Doctrine query budgets are unchanged.
