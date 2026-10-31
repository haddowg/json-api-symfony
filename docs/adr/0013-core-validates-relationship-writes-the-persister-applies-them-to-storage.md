# Core validates relationship writes; the persister applies them to storage

Both reference data layers store related objects rather than ids, so core's baseline of writing related ids onto a column does not fit. Core owns the request-shape rules (cardinality, the relation's mutability flags), and `DataPersisterInterface::mutateRelationship()` resolves linkage ids to related objects and mutates the association. A whole-resource write strips `data.relationships` from the body before core hydrates it and applies each named relationship through the same seam, in replace mode and without flushing so the create or update owns the single commit: attributes go through core, associations through storage.

## Consequences

- Embedded relationships are applied before core hydrates attributes, so a flattened `on()` attribute writes onto the target the same body just linked.
- A relation with a `fillUsing()` hook is written by its hook instead of the persister, inside the same linkage scoping and gates.
