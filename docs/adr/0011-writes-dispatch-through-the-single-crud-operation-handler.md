# Writes dispatch through the single CRUD operation handler

`Server::withHandler()` takes one handler, and the read path already was one
generic handler that dispatches on the operation type and resolves a per-type
provider. Rather than add a second handler plus a composite to satisfy the
single-handler API, the read handler grew create/update/delete arms and was
renamed `CrudOperationHandler` — keeping one dispatch point over both the
`DataProvider` (reads, and loading an update/delete target) and the
`DataPersister` (writes), driving core's per-type hydrator (`Server::hydratorFor()`)
in between. Writes share one shape: resolve the persister, hydrate, commit,
render. Create renders `201` with a `Location` header; update renders `200`;
delete renders `204` (the update/delete target is loaded through the read
provider first, so a missing one is a clean `404`).

The handler stays the thin per-operation dispatcher the read path established; the
generic zero-handler CRUD engine is a later capstone, built as a refactor of this
proven handler over the SPIs rather than speculatively now. Body well-formedness
and top-level-member checks for write verbs run in the `RequestListener` by
calling core's `RequestValidator` methods directly (no `Middleware\*`), the same
way negotiation already does.
