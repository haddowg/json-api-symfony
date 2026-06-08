# Writes land through a DataPersister SPI mirroring the read DataProvider

Core hydrates a domain object from the request document but never persists it —
persistence is the integration's job — so the bundle needs a storage seam for
create/update/delete the way `DataProviderInterface` is the seam for reads. The
`DataPersisterInterface` is the deliberate write twin: per-type resolution
through a `DataPersisterRegistry` (the same first-`supports()`-match over a tagged
iterator, with the reference Doctrine persister as the `-128` fallback), an
in-memory witness, and a Doctrine reference implementation — so a write spec test
runs identically on both and a failure localizes to one persister's execution.

Two shape choices differ from the read SPI for good reason. The contract is **not
templated** over the entity type (entities flow as `object`): the handler resolves
the persister by type and a write that both consumes and returns the entity would
make the type invariant, buying no safety the registry's `object` resolution
needs. And it carries an `instantiate()` method: the persister owns the storage
mapping, so it owns constructing the blank instance the hydrator populates on
create. The reference in-memory provider and persister share one
`InMemoryStore`, mirroring how the Doctrine pair shares one `EntityManager`, so a
written resource is immediately readable. **Relationship mutation is deferred** —
this SPI covers whole-resource writes only; the `/relationships/{rel}` endpoints
arrive with the relationship phase.
