# Data access goes through a Provider/Persister SPI, with Doctrine as the reference implementation

Welding the generic CRUD capstone directly to Doctrine would be the smallest build
but would exclude every other store and block testing without a database. Instead
the generic handler is storage-agnostic over a bundle SPI — a `DataProvider` (fetch
one / collection) and `DataPersister` (create / update / delete /
relationship-mutate), resolved per resource type — with Doctrine as the reference
implementation (building queries through core's `FilterHandlerInterface` /
`SortHandlerInterface`) and an in-memory implementation as the test double and
conformance witness, which keeps a finding attributable to either the core seam or
the data mapping.

This mirrors core's own metadata-versus-execution split one layer up
(`haddowg/json-api` ADR 0007). Other stores plug in by implementing the SPI, and the
generic engine (a later phase) is built once over it as a refactor of the proven
per-type handlers. The cost — more interfaces than direct Doctrine — is accepted as
the price of a platform rather than a Doctrine-only tool.
