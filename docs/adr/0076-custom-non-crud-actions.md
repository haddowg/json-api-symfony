# Custom / non-CRUD actions as a standalone capability under a reserved `-actions` segment

Laravel-JSON:API-style custom actions (publish, archive, file upload, batch
command) are author-defined non-CRUD endpoints that hang off a resource type but
do not fit the CRUD verbs. We add them as a **standalone capability** — a class
implementing `ActionHandlerInterface` declared with `#[AsJsonApiAction]` and
discovered by autoconfiguration (exactly the standalone-serializer/hydrator
pattern, ADR 0024), with **no `AbstractResource` sugar** — mounted under a
reserved literal `-actions` URL segment at two scopes: resource
`POST /{uriType}/{id}/-actions/{action}` and collection
`POST /{uriType}/-actions/{action}`. The segment is a safe future-proof reserved
literal because JSON:API member names cannot begin with a dash, so it can never
collide with a resource id or relationship name (it mirrors `laravel-json-api`),
and the [route loader](../routing.md) emits action routes **before** the generic
`/{uriType}/{id}` and `/{uriType}/{id}/{relationship}` routes so the literal is
never captured as an `{id}` or `{relationship}`.

## Why a first-class core operation

An action dispatches through a new core `CustomActionOperation` (a
`JsonApiOperationInterface`) and `Server::dispatch()`, rather than a bundle-only
side path. `Server::dispatch()` already routes *any* operation through
`validateStrictQueryParameters()` + the request-wide serving/authz gate
(`ServingEvent`) into the single `OperationHandlerInterface::handle()`, so the
action inherits strict-query validation, the serving gate, and the handler
contract for free — and the global [`CrudOperationHandler`](../lifecycle.md) (still
decoration-overridable, ADR 0028) gains exactly one arm delegating to an optional
`ActionInvoker`. The bundle constructs the operation (core's CRUD operation
factory stays CRUD-pure); this is one of only **two** sanctioned core touches for
the feature.

## Why the request and response documents are decoupled from the mount type

An action reuses the mount type's serializer/hydrator/links/authz by default, but
its `inputType`/`outputType` may point at any other registered type — including a
**standalone serializer/hydrator pair** with no endpoints of its own — so an
action can accept a bespoke command document and/or return a bespoke response
document while staying valid JSON:API, without forcing the author to shape both
sides of the exchange as the mount resource.

## Why three input modes (and the one negotiation relaxation)

Actions span a permissive input range that CRUD does not: `None` (no body, the
default — `Content-Type` not required), `Document` (parsed + structurally and
semantically validated as JSON:API against `inputType`, hydrated to an object),
and `Raw` (an escape hatch for non-JSON:API bodies such as a `multipart/form-data`
upload — the handler reads the raw body and uploaded files). `Raw` requires the
**only** other core touch: `RequestValidator::negotiate()` gains a
`bool $requireJsonApiContentType = true` parameter so the bundle can relax the
**request** body content-type assertion for raw-input actions while keeping
**response** `Accept` negotiation intact. The output contract stays **strict** — a
handler returns a core response value object (`DataResponse` / `MetaResponse` /
`NoContentResponse` / `ErrorResponse`), never a raw response, so links and error
rendering flow through the existing `ViewListener` unchanged.

## Why per-action security rides an event, not the resource registry

Authorization is two reused layers: the request-wide serving gate (already fired
by `Server::dispatch()`), plus an optional per-action `security` expression carried
**on a new `BeforeActionEvent`** dispatched by the `ActionInvoker` after entity
resolution and before the handler. Carrying the expression on the event (per
action, not per type) means the existing `ResourceSecuritySubscriber` evaluates it
against the subject — the resolved entity for resource scope, `null` for collection
scope — exactly mirroring `onBeforeCreate`/`onBeforeUpdate` (`AccessDeniedException`
→ `403`), with **no `ResourceSecurityRegistry` change**. `BeforeActionEvent` /
`AfterActionEvent` are also public [lifecycle events](../lifecycle-hooks.md), for
symmetry with the CRUD hooks.
