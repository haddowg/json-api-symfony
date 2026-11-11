# An unscoped to-many's rendered linkage loads in one batch per page

ADR 0069 left a to-many with no default read order, no filter default and an unscoped provider to render its linkage off the model. On Doctrine that initialises each parent's collection with its own query, so a `withData()` to-many on a page of parents cost one query per parent. The bundle now loads that linkage through the parent provider's `fetchRelatedCollectionBatch()` for the parents whose collection the server's load state reports unloaded, the same batch an include of it runs, and relations stored on the same association share that load.

## Considered Options

- Writing the loaded members onto each entity's collection, as Laravel's `setRelation()` does: the bundle never writes onto the model, so the members go out of band like every other batched relation.
- Batching every parent whatever the load state says: a store whose models hold their relations in memory, such as the in-memory provider, would pay a batch for values it already has.

## Consequences

- The members come in the order the batch returns them, as they already did when the relation was included. A Doctrine `#[ORM\OrderBy]` on the association no longer orders this linkage; a relation or related-type `defaultSort()` does.
- A pivot `belongsToMany` and a to-one keep their current reads: the pivot batch reads one parent at a time anyway, and a to-one's identifier needs no load.
- With no load state wired every relation counts as loaded, so nothing changes for the in-memory provider.
