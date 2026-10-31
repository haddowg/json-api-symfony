# Every relation a document renders loads through the provider that serves its related endpoint

Included resources, rendered linkage, `?withCount`, the Relationship Queries window and the hops of `on()` attributes all load on the request's own server through the related type's provider, batched once per relation per level with `fetchRelatedCollectionBatch()` (per parent when that provider is not the parent's), and the loaded members reach core through its out-of-band linkage seams instead of being written onto the entity. A relation is read straight off the model only when its provider declares that equals its related read (`ScopesRelatedReadsInterface`) or the author computes the value; anything else fails loudly. Loading used to be a pure optimisation with a lazy fallback, but under a scoped provider or a Doctrine extension a lazy read off the entity renders rows the related endpoint hides.

## Considered Options

- Keep the lazy fallback and document it: under a scope it renders hidden rows.
- Hide unloadable linkage through the load-state predicate: it covers only lazy, not-included relations and changes the document's shape.
- Write the scoped value onto the entity: it fails on a private collection with no setter, and a later flush in the same request (an atomic batch) would persist a filtered collection or a nulled to-one.

## Consequences

- Doctrine loads a many-to-many or owning-side batch as scalar `(parent, related)` id pairs plus one id load, because ORM hydration dedups a root entity across rows and would lose parents that share a member.
- A computed relation whose related type is scoped keeps only the rows that type's provider admits (`AdmitsRowsInterface`, one `IN` probe per relation per level), and a polymorphic to-many narrows each scoped member type the same way, before any page, sort or count.
- An `identifiedBy()` `MorphTo` loads its targets by id through each type's provider (`FetchesByIdsInterface`), and for linkage alone loads only the types whose rows could be hidden.
- Author-declared `on()` eager loads ride the same batch, bypass the client include safeguards and never add to `included`.
