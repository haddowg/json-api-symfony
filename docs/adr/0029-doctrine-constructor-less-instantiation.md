# Doctrine entities are instantiated without invoking the constructor on create

The reference Doctrine persister builds a new entity on create via Doctrine's
constructor-less instantiation (`ClassMetadata::newInstance()`) — the same
mechanism the ORM uses to hydrate entities on read — rather than
`new $entityClass()`. This lets entities with required constructor arguments work
under the generic zero-handler engine, keeping the genericity promise intact for
any mappable entity. Constructor invariants and defaults do not run on create
(consistent with read-hydration); an application that needs them overrides
`instantiate()` via a custom `DataPersister`.
