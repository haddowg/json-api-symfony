# Relationship linkage load-state is a storage-aware predicate, wired only for Doctrine

A relation that opts into core's `dataOnlyWhenLoaded()` policy must answer
"is this linkage already in memory?" **without triggering a load** — but the core
library is storage-agnostic and cannot know. Core's seam
(`RelationshipLoadStateInterface`, injected via `Server::withRelationshipLoadState()`,
default `null` = always-loaded) leaves that answer to the data-layer adapter. The
bundle implements it as the reference `DoctrineRelationshipLoadState`: for a
to-many it reports loaded only when the backing association is an
**already-initialised** `PersistentCollection` (read via `ClassMetadata`,
`isInitialized()` consulted directly so the check never iterates or initialises
the collection); for a to-one it reports loaded unconditionally, because a lazy
`ManyToOne` proxy carries its identifier and emitting the resource identifier
needs no database round-trip.

It is threaded into the `Server` by the `ServerFactory` through core's injector,
and registered **only when the Doctrine adapter is wired** — the
`DoctrineEntityMapPass` removes it on the same empty-entity-map condition as the
Doctrine provider/persister, so the `ServerFactory`'s optional dependency
resolves to `null` (core's always-loaded default) in an in-memory or
non-Doctrine-integrated application, where related objects are materialised
anyway. The predicate maps the JSON:API relationship to its storage association
by the relation field's `column()` and `isToMany()`, and treats any relation it
cannot reason about (an unmapped column, a non-managed model) as loaded — so it
never changes the behaviour of a relation outside its competence.
