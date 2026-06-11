# Relationship mutations run through a DataPersister relationship seam — core validates, storage applies

The `/{type}/{id}/relationships/{relationship}` linkage routes gain the three
mutating verbs (`PATCH` replace, `POST` add, `DELETE` remove) alongside the
existing `GET`, all on the one four-segment route. The `RequestListener` now parses
and validates the body for a relationship `DELETE` too (a resource `DELETE` stays
body-less, but a relationship `DELETE` carries the `{data:[…]}` linkage to remove);
the resource-document *required-top-level-member* rule is **skipped** for
relationship-endpoint bodies, because a relationship body's `data` is legitimately
`null` (to-one clear) or `[]` (to-many clear) — shapes that rule would reject — and
the exact linkage shape is instead validated by core's relationship-linkage parser
(`getRelationshipLinkageToOne()` / `getRelationshipLinkageToMany()`).

The `CrudOperationHandler` gains `UpdateRelationship` / `AddToRelationship` /
`RemoveFromRelationship` arms sharing one shape: load the parent through the read
provider (`404` when absent), resolve the relation by name (a semantic
`RelationshipNotExists` `404` when unknown), then **validate the request shape** —
cardinality (add/remove only on a to-many → `RelationshipTypeInappropriate` `400`)
and the relation's mutability flags (`allowsReplace()`/`allowsRemove()` →
`FullReplacementProhibited` / `RemovalProhibited` `403`) — letting core's typed
exceptions propagate to the exception listener as the right status. The validated
mutation is then applied through a **new `DataPersisterInterface::mutateRelationship()`
seam**, rendering the resulting linkage (`200`).

The split is deliberate: **core owns the request-shape rules, the persister owns the
storage-correct apply.** Core's per-field `AbstractRelation::hydrateRelationship()` /
`applyToMany()` baseline writes the related *ids* onto a scalar column — but both
reference data layers store related **object references** (a to-one holds the related
entity, a to-many a collection of them), not ids, so that baseline does not fit. The
persister, which already owns the storage mapping (ADR 0010), is therefore the only
place that can resolve a linkage id back to the related object/reference and mutate
the association: the Doctrine persister resolves each id to a managed reference via
`EntityManager::getReference()` on the *related* type's mapped entity class (read from
the same `type → entity` map), mutates the to-one property or the to-many collection
(setting the owning side when the parent's association is the inverse side, so the
foreign key is actually written), and flushes; the in-memory persister resolves each
id to the stored object through a related-object resolver across the per-type stores
and sets the parent's property (object for a to-one, deduplicated object-list for a
to-many), so a follow-up read still returns objects. The whole-resource write seam
(`create`/`update`/`delete`) is unchanged. The same dual-provider conformance suite
re-fetches after every mutation to prove the change persisted on both providers.
