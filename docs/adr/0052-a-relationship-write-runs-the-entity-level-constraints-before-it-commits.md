# A relationship write runs the entity-level constraints before it commits

A rule such as "an integration user needs at least one company" lives in the entity-level pass, which ran only on create and update, so a write to `/relationships/companies` could break it. The relationship endpoints, and the add, update and remove operations on a relationship ref in an atomic batch, now apply the change inside the persister's transaction, run the entity-level pass on the changed parent in the update context, and roll back on a violation. Only the entity-level constraints run: the request carries no attributes, and re-checking stored ones would refuse a relationship write over data the write never touched.

## Consequences

A violation is a `422` at the pointer the pass gives on an update, so `atPath('companies')` points at `/data/relationships/companies`, and in an atomic batch the loop prefixes it with the operation index and the whole batch rolls back. An application whose stored data already breaks an entity-level constraint now gets `422`s on relationship writes to those resources. A persister without `TransactionalDataPersisterInterface` has already applied the change when the pass refuses it.
