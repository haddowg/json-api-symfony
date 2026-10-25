# Included and linkage data load through the related endpoint's provider

> Update: a computed relation now follows its related type's scope. Its `extractUsing()` closure computes the candidates, and when its related type is scoped the router keeps only the rows that type's provider admits, before any criteria run, so linkage, `included`, both endpoints, `?withCount`, the Relationship Queries window and `on()` through a to-one all use the narrowed set and every count matches it. The probe is one `WHERE id IN (…)` query per relation per level on Doctrine (`AdmitsRowsInterface`), one `fetchOne()` per row on a scoped provider that can't do better, and nothing at all when the related type isn't scoped. A computed polymorphic to-many into a scoped type is refused, like any polymorphic to-many into one. The Laravel adapter's ADR 0050 covers the same case.

The include batcher resolved metadata on the default server only, so a type served on a
named server rendered its includes and linkage straight off the entity, and it batched
through the parent type's provider, so a related type served by its own scoping
provider was never asked. Either way a document could render rows the related endpoint
hides. Every relation a document renders data for (included, `withData()`, a loaded
collection, a to-one's linkage, a pivot `belongsToMany`, `?withCount`, the Relationship
Queries window, the hops of an `on()` attribute) now loads on the request's own server
through the provider that serves its related endpoint: batched when that is the parent's
provider too, per parent otherwise. The loaded members, and a to-one's target or none,
reach core through its out-of-band linkage seams (core 1.4's
`ToOneRelationshipLinkageInterface` for to-one) instead of being written onto the
entity. A relation is read off the model only when its provider says that equals its
related read (`ScopesRelatedReadsInterface`, assumed `true` when a provider does not
implement it), or when the author computes its value (`extractUsing()`, a
non-association getter). Anything else fails with a `\LogicException`.

## Considered options

- **Keep the lazy fallback and document it.** Batching was described as a pure
  optimization, so falling back looked harmless; with a scope it renders hidden rows.
- **Hide unloadable linkage through the load-state predicate.** It omits `data` only for
  a lazy relation that is not included, and changes the document's shape, so it cannot
  cover includes, `withData()` or to-one linkage.
- **Write the scoped value back onto the entity.** It fails on a private collection
  with no setter, and replacing a managed collection with a filtered one, or nulling a
  hidden to-one, would be persisted by a later flush in the same request (an atomic
  batch).

## Consequences

- A scoped to-one costs one `WHERE id IN` query per relation per level (one probe per
  parent for a `MorphTo`, or when the related type has its own provider), skipped for
  targets the document already renders.
- An update scopes the `on()` hops the body does not set before it hydrates, so a
  flattened attribute is written onto the target the related endpoint serves.
- A polymorphic to-many to a scoped type is refused: core reads its linkage off the
  model.
- A custom provider that does not implement `ScopesRelatedReadsInterface` has its
  relations loaded through it, and a polymorphic to-many or an unsupported batch on it
  is refused.
