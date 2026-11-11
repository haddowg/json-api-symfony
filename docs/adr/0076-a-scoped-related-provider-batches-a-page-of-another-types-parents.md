# A scoped related provider batches a page of another type's parents

When a relation's related type has its own scoped provider, the bundle asks that provider's `fetchRelatedCollectionBatch()` for the whole page of parents, keyed by their wire ids, and reads one parent at a time only when it answers `unsupported()` or `computed()`. Reusing the existing method keeps the SPI the same size and leaves every existing provider on its per-parent path, since `unsupported()` is the `AbstractDataProvider` default. For a to-one the batch is the source of the target on every read path, so a type served by a grouped query needs no getter on the parent model; the batch is kept for the request and dropped after a write, so the include, the linkage, the filter check and the endpoints share one call.

## Considered Options

- A new interface for cross-type batches: one more capability for authors to find, when the existing method's signature already names the parent type.
- Using the batch only as a scope check on the model's value: a to-one with no getter would still render `null` everywhere.

## Consequences

- `computed()` from a related provider means the model holds the value, which is still read per parent and checked against the related type's scope, unlike the parent's own provider, where `computed()` renders the model's value as it is.
- A to-one is asked with empty criteria; a `relatedQuery` or `?filter` check runs `relatedToOneMatches()` against the batched target.
- A pivot `belongsToMany` and a polymorphic relation keep their per-parent reads.
