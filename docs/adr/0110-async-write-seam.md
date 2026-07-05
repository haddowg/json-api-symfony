# A persister accepts a write for async processing via `AcceptedForProcessing`

- **Status:** accepted

A `DataPersister::create()` / `update()` may return an `AcceptedForProcessing`
marker in place of the persisted entity to signal that it dispatched the write for
**asynchronous processing** (a Symfony Messenger message, a queue) rather than
committing it. The `CrudOperationHandler` renders the marker as core's `202`
`AcceptedResponse` — the pollable job resource (or a meta-only status document) with
the `Content-Location` and any `Retry-After` the persister set — instead of the
`201`/`200` a synchronous write returns. The completion leg is a custom action
returning core's `303` `SeeOtherResponse` (`ActionContext::seeOther()`), so the full
JSON:API 1.1 asynchronous-processing lifecycle is expressible.

**Why.** Async writes were reachable only imperatively (a custom action hand-building
a response). This is the thin, unopinionated seam that makes them declarative without
baking a queue into the bundle: the persister — the write-side component that already
owns the storage decision — owns the dispatch decision too, and the only new surface
is a return-value marker plus a handler branch (no new SPI, registry, or interface).
*How* the work is queued stays the application's choice (see `docs/async.md` for the
Messenger recipe); the bundle only owns the spec-correct `202`/`303` wire shape, which
rides core's framework-neutral `AcceptedResponse`/`SeeOtherResponse` (core ADR 0116)
so the Laravel package emits the identical bytes over its own queue.

## Consequences

An async accept cannot participate in an Atomic Operations batch — it defers the write
past the batch's all-or-nothing commit — so a marker returned while a batch is in
flight is refused (`AsyncWriteNotAllowedInAtomicOperation`, `422`, rolling the batch
back). Only `create()`/`update()` carry the seam (they return `object`); `delete()`
returns `void`, so an async delete is out of scope for now. Core's
`OperationHandlerInterface`/`Server::dispatch()` unions and the action handler/invoker
unions gained `AcceptedResponse`/`SeeOtherResponse` so the responses type-check
end-to-end. OpenAPI does not yet document an operation's async `202`/`303` responses —
that is a follow-up, gated on an operation-level "may respond async" declaration.
