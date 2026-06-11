# Whole-resource relationship hydration reuses the DataPersister relationship seam — attributes via core, associations via storage

A `data.relationships` member on a whole-resource write (`POST /{type}`,
`PATCH /{type}/{id}`) sets the parent's associations the **same way** the
relationship-endpoint mutations do (ADR 0017): through the
`DataPersisterInterface::mutateRelationship()` seam. The `CrudOperationHandler`
strips the `relationships` member from the body before handing it to core's
per-type hydrator, so core hydrates **only** the id + attributes; the handler then
applies each named relationship through the persister seam in `Mode::Replace`,
resolving each linkage id to the related reference (Doctrine) / stored object
(in-memory) and writing the association.

The strip is deliberate. Core's per-field `AbstractRelation::hydrateRelationship()`
writes the related *ids* onto a scalar column — but both reference data layers store
related **object references** (a typed `?AuthorEntity $author`, a `Collection`), not
ids, so letting core hydrate a `data.relationships` member would assign a string id
to a typed association property and `TypeError`. Rather than change core's hydrator
to know about storage (it can't — it owns no mapping), the bundle keeps the
**attributes-via-core / associations-via-storage** split it already settled on for
the relationship endpoints, and reuses the exact same seam: no new SPI surface, no
core change. The strip is a view-layer transform on the request (the handler copies
the parsed body without `data.relationships` and hydrates that copy), so core stays
unaware — it hydrates what the request contains, and the bundle controls what it
contains.

Flush ownership is the one new wrinkle. The endpoint mutations commit per call, but
a create applies relationships **before** the entity is persisted, so flushing
mid-association would flush a not-yet-persisted target. `mutateRelationship()`
therefore gains an optional `bool $flush = true`: the whole-resource path applies
every relationship with `$flush = false` and lets the single subsequent
`create()`/`update()` own the one commit. A write with no `data.relationships` is
unchanged, and a partial `PATCH` naming only some relationships leaves the
unmentioned associations untouched (only the named ones are replaced). The same
dual-provider conformance suite re-fetches after every write — the Doctrine subclass
clearing the identity map first — to prove the foreign keys were actually written on
both providers.
