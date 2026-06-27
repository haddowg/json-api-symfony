# Surface a relation's pivot fields to the OpenAPI projector

Core's projector now types a pivot-backed relation's linkage `meta.pivot` from
`RelationMetadataInterface::pivotFields()` (core ADR 0100). The bundle's
`RelationMetadata` adapter satisfies that contract by returning
`PivotFields::declaredFor($relation)` — the very source it already derives the pivot
`?sort` vocabulary from (bundle ADR 0097), so the advertised sort tokens and the
typed `meta.pivot` describe the same fields. It is empty for any non-pivot relation,
so the projector types `meta.pivot` exactly where the runtime renders it.

No new bundle machinery: the pivot fields are read off the `belongsToMany` relation
(`BelongsToMany::pivotFields()`), and core projects each with the same
`SchemaProjector` the attributes use. The example catalogue's `playlists.orderedTracks`
relation is the witness — its `position`/`weight`/`addedAt` pivot fields now appear,
typed, in the served `PlaylistsOrderedTracksRelationship[Document]` linkage.
