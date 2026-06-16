# Per-operation lifecycle hooks over Symfony events, with resource-method sugar

The only author seam over the CRUD flow today is decorating the single global
`CrudOperationHandler` — coarse (one wrapper owns every concern) and forcing
authorization, delete-guards, custom-action shaping and imperative validation all
through it. We add a **per-operation lifecycle-hook set** the handler fires as
**Symfony events** at fixed points — `serving` (server-level, once per request,
core ADR 0050), then per operation: create `BeforeSave → BeforeCreate → [persist]
→ AfterCreate → AfterSave`, update `BeforeSave → BeforeUpdate → [commit] →
AfterUpdate → AfterSave`, delete `BeforeDelete → [delete] → AfterDelete`, the
relationship endpoints `BeforeRelationshipMutate → [apply] →
AfterRelationshipMutate`, and reads `AfterFetchOne` / `AfterFetchCollection`. A
**before** event carries the entity *mutable* (a set field is persisted by the
ensuing flush) and **aborts by throwing** a `JsonApiExceptionInterface` — the
route-scoped `ExceptionListener` renders it (403 guard/authz, 422 imperative
validation, 409 conflict) so no commit happens; an **after** event fires
post-commit and may **replace** the response value object (custom-action shaping),
which the handler reads back.

We expose the same hooks **two equivalent ways**: an application registers a
subscriber on the event classes (a cross-cutting concern), **or** a resource
implements the bundle `ResourceLifecycleHooksInterface` (`use
ResourceLifecycleHooksTrait;` for no-op defaults) and overrides only the hooks it
wants (a per-type concern) — the built-in `ResourceHookSubscriber` routes each
event to the matching method, so the methods are *sugar over the events* with one
dispatch point and no per-type subscriber registration. The hook interface, the
events, and the small `HookContext` value object live in the **bundle**, not on
core's `AbstractResource`, so core stays free of any dependency on the bundle's
event/context types: a resource opts in here without core knowing the hooks exist.
The server-level `serving` gate is a **core** Server seam (core ADR 0050) fired in
`Server::dispatch()` so core-direct consumers get it too; the bundle's
`ServerFactory` registers one `withServing()` handler that turns it into a bundle
`ServingEvent`. The per-operation hooks are bundle-only because core has no CRUD
lifecycle of its own — persistence lives in the bundle's `DataPersister`, the flow
in `CrudOperationHandler`. The whole seam is the keystone that unlocks
authorization, delete-guards and the imperative-validation escape hatch as ordinary
listeners rather than handler decorations; the dispatcher injection is optional, so
the hooks are simply inert when `symfony/event-dispatcher` is absent.
