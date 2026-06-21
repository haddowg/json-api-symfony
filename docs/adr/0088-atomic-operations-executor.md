# The Atomic Operations executor runs each sub-op through the one handler in-process

The headline `POST /operations` endpoint (opt-in, `json_api.atomic_operations.enabled`) is
served by an executor that drives core's framework-agnostic `AtomicLoop` with a bundle
`AtomicLoopBackend`. Rather than re-implement create/update/delete/relationship-mutation,
the backend rewrites each parsed `OperationDescriptor` into the matching core CRUD
operation VO and dispatches it through the **same** `CrudOperationHandler` *in-process* —
calling `handle()` directly, never `Server::dispatch()` — so serving fires once for the
whole batch (not per sub-op) and the per-op `After*` hooks defer to post-commit through the
Slice-B `WriteTransactionContext`. The handler gained one `AtomicOperationsOperation` arm; a
synthetic per-op `JsonApiRequest` (method + path + `{data: …}` body) is derived from the
batch request so the request-aware predicates and `_jsonapi_server` resolve, and the verb is
load-bearing (an `update` is `PATCH` so its `data.id` is the target, not a rejected
client-generated create id).

**Decoration is batch-scoped, deliberately.** Because the sub-operations re-enter the one
handler instance directly, a handler decorator wraps the batch as a whole (it sees the single
`AtomicOperationsOperation`), not each sub-op; per-sub-op decoration is out of scope and
documented as such. **Pre-flight** resolves every participating type's persister before
opening anything and refuses the batch — so a partial non-rolled-back batch can never occur —
on two grounds: a type with no registered persister is an unknown type, a client error
(`AtomicTargetTypeUnknown`, 404, mirroring the routing miss a direct CRUD call would hit,
there being no routing step inside a batch); and a type whose persister is not a
`TransactionalDataPersisterInterface` cannot transact (`AtomicOperationsNotSupported`, 403).

**The all-or-nothing guarantee is scoped to a single transactional persister per batch.** The
default — one shared Doctrine `EntityManager` (every entity-mapped type) or the in-memory
store — is one persister, genuinely atomic. A batch MAY span more than one distinct
transactional persister (e.g. a custom persister for one type alongside the Doctrine fallback
for another, or two `EntityManager`s); the executor then commits each in turn, and because
there is no two-phase commit across persisters a later commit can fail *after* an earlier one
has already made its writes durable — which cannot be undone. `commit()` does roll back every
persister that has NOT yet committed (so no open transaction leaks) and re-raises, but the
already-committed ones stand: a multi-persister batch is therefore atomic only up to the first
failing commit. The cross-store in-memory witness is exempt — its `commit()`
(`discardSnapshot`) cannot fail, so its multi-store batches stay fully all-or-nothing. Apps
needing strict atomicity across separate stores must back the batch's types with one
transactional persister.

`lid`s are rewritten to real ids (in the `ref` and in every linkage inside `data`) before
dispatch and registered after an `add`, so a forward reference is a clean `LocalIdNotFound`
and a duplicate a `LocalIdConflict`, each pointer-prefixed with the operation index by the
loop. WHICH `data` lids are references is driven by the resolved target, not `data`'s shape:
a relationship endpoint's `data` is linkage (each identifier's own lid is rewritten), whereas
a resource endpoint's `data` is a resource object whose OWN top-level `lid` (the create's
local id) is left intact and only its `relationships[*]` linkage is rewritten — so an
attribute-less `add` carrying just `{type, lid}` is not mis-resolved as a forward reference. An `href` target is resolved by matching the path against the router method-agnostically
(the verb rides the `op`, not the route). A sub-op that *returns* a `404`/`400` ErrorResponse
(rather than throwing) is re-raised through a `SubOperationFailed` adapter so the whole batch
still rolls back at that index. The route carries no `_jsonapi_type` — the batch has no single
primary resource — so the listener branches on a dedicated `_jsonapi_atomic` marker and
negotiates the atomic `ext` on both `Content-Type` (415) and `Accept` (406).
