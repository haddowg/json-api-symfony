# Atomic Operations run each operation through the CRUD handler inside a segregated transaction

`POST /operations` rewrites each operation into the matching core CRUD operation and calls the same `CrudOperationHandler` in-process (not `Server::dispatch()`, so serving fires once per batch), inside a transaction opened through `TransactionalDataPersisterInterface`, a separate interface so `DataPersisterInterface` stays unchanged. `After*` hooks are buffered and drained after the commit (discarded on rollback, and a throwing one logged rather than failing a durably committed batch), and every participating type's persister and the request's scope grants are checked before the transaction opens, so a refused batch never runs a write.

## Consequences

- All-or-nothing holds for one transactional persister. A batch spanning several commits them in turn with no two-phase commit, so it is atomic only up to the first failing commit.
- Handler decorators see the batch, not each operation.
- Doctrine's per-operation `flush()` inside the open transaction still assigns auto-increment ids, which `lid` resolution needs.
- A batch always answers `200` with `atomic:results` (never `204`), `?include` and `?fields` are not processed, and an error declares the atomic `ext` only when the request named it.
