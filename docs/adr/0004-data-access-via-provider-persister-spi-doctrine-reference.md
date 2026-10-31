# Data access goes through a provider/persister SPI, with Doctrine as the reference implementation

The generic engine is storage-agnostic over a `DataProvider` (reads) and a `DataPersister` (writes, including relationship mutation), each resolved per type, with Doctrine as the reference implementation and an in-memory pair as the test double and conformance witness, so a finding is attributable either to the shared engine or to one store's execution. Welding the engine to Doctrine would have been smaller, but it would exclude every other store and block testing without a database.

## Consequences

- Resolution is the standard tagged-iterator contract: providers and persisters are consulted in descending tag priority and the first `supports()` wins. The Doctrine pair registers at `-128`, so an application's own provider shadows it for the types it supports with no configuration.
- The persister is not templated over the entity type (entities flow as `object`), and it owns `instantiate()` because it owns the storage mapping.
