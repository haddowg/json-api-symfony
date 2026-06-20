# Custom / non-CRUD actions (G13) — build spec (APPROVED)

**Status:** approved by Greg 2026-06-18 (standalone-only; decoupled request/response
document; first-class core operation). This is the authoritative spec for the build
workflow — agents follow it verbatim. **Standing rule:** do not diverge from this plan;
if a step forces a divergence (esp. a core public-API change beyond what's listed here,
or a perf regression), STOP and raise a GO/NO-GO — do not substitute.

Delivers Laravel-JSON:API-style custom actions: author-defined, non-CRUD endpoints that
hang off a resource type under a reserved `-actions` segment, reusing the type's
serializer/hydrator/links/authz but able to associate a **custom request/response pair**.

## 1. The shape

Two URL scopes, fixed `-actions` segment (future-proof if the JSON:API spec ever defines
its own actions pattern — mirrors `laravel-json-api`):

- **Resource scope:** `POST /{uriType}/{id}/-actions/{action}` — the `{id}` is resolved to
  an entity (via the type's `DataProvider`) before the handler runs.
- **Collection scope:** `POST /{uriType}/-actions/{action}` — no id.

`{action}` is a **single path segment** (one action name) for v1. The `-actions` segment
cannot collide with a resource id or relationship name (JSON:API member names cannot begin
with a dash), so it is a safe reserved literal. Methods are author-declared (default
`POST`; any of GET/POST/PATCH/PUT/DELETE).

## 2. Declaration surface — standalone only

No `AbstractResource` sugar. An action is a standalone class implementing
`ActionHandlerInterface`, declared with `#[AsJsonApiAction]` and discovered by
autoconfiguration (exactly the standalone-serializer/hydrator pattern, ADR 0024).

```php
#[AsJsonApiAction(
    type: 'articles',               // mount type: the {uriType} segment + the DEFAULT serializer/hydrator
    path: 'publish',                // the {action} segment
    methods: ['POST'],              // default ['POST']
    scope: ActionScope::Resource,   // Resource (default) | Collection
    input: ActionInput::None,       // None (default) | Document | Raw
    inputType: null,                // Document mode only: hydrator type for the request doc; defaults to `type`
    outputType: null,               // serializer type for the response doc; defaults to `type`
    server: null,                   // multi-server assignment; defaults to the implicit `default`
    security: "is_granted('PUBLISH', subject)",   // optional authz expression (see §6)
    name: null,                     // optional route-name override
)]
final class PublishArticle implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse|MetaResponse|NoContentResponse|ErrorResponse
    {
        $article = $context->entity();              // resolved Article (resource scope)
        // …apply the action…
        return DataResponse::fromResource($article, $context->serializer());  // outputType serializer
    }
}
```

`ActionScope` enum: `Resource` | `Collection`.
`ActionInput` enum: `None` | `Document` | `Raw`.

The request and response documents are **decoupled from the mount type**: both default to
the mount type's hydrator/serializer, but `inputType` / `outputType` may point at any other
registered type — including a **standalone serializer/hydrator pair** (a Phase-4 type with
no endpoints of its own) — so an action can accept a bespoke command document and/or return
a bespoke response document while staying valid JSON:API.

## 3. Input contract — permissive (three modes)

| Mode | Request handling | What the handler receives |
| --- | --- | --- |
| **`None`** (default) | no body read; request `Content-Type` not required | `$context->input()` is `null` |
| **`Document`** | parsed + structurally validated as JSON:API (negotiate, JSON decode, top-level members, optional opis schema) **and** semantically validated through the Validator bridge against `inputType`'s constraints | `$context->input()` is the **hydrated object** of `inputType` |
| **`Raw`** (escape hatch) | request `Content-Type` negotiation **relaxed** (a `multipart/form-data` upload is not `application/vnd.api+json`); no JSON-API body parsing/validation | `$context->request()` exposes the raw body + uploaded files; `$context->input()` is `null` |

**Document hydration target.** For `Document` mode the bundle resolves a fresh input object
and hydrates the body into it, then runs the Validator bridge:
1. if the handler also implements **`ActionInputFactoryInterface`** (`newInput(JsonApiRequestInterface $body): object`), use that object — this is how a **bespoke command DTO** with only a serializer/hydrator pair (no persister) supplies its blank instance;
2. otherwise instantiate via the `inputType`'s persister — `DataPersisterRegistry::forType($inputType)->instantiate($inputType)` (the common case where `inputType` defaults to the mount `type`).

The hydrated `input` and the resolved `entity` (resource scope) are **independent** — a
resource-scope `Document` action that wants to mutate the existing entity reads both and
applies `input` onto `entity` in the handler (no implicit merge — predictable, no magic).

## 4. Output contract — strict (JSON:API document or 204)

`ActionHandlerInterface::handle()` returns a **core response value object**:
`DataResponse` | `MetaResponse` | `NoContentResponse` | `ErrorResponse`. No raw response.
`DataResponse`/`MetaResponse` render through the `outputType` serializer (defaults to the
mount type) via the existing `ViewListener` → so links, JSON:API object, and error rendering
are reused unchanged. `NoContentResponse` yields a bodyless `204`.

`ActionContext` exposes conveniences so the handler need not thread the server:
- `entity(): ?object` — resolved entity (resource scope) / `null` (collection scope)
- `input(): ?object` — hydrated input (Document mode) / `null`
- `request(): JsonApiRequestInterface` — always; raw body + uploaded files for Raw mode
- `queryParameters(): QueryParameters`
- `serializer(): SerializerInterface` — `server->serializerFor(outputType)`
- `server(): ResolvingServerInterface`
- convenience factories: `data(object|iterable $data): DataResponse`, `meta(array $meta): MetaResponse`, `noContent(): NoContentResponse` (each pre-wired to the `outputType` serializer)

## 5. Dispatch — first-class core operation (decision A)

A new core **`CustomActionOperation`** implements `JsonApiOperationInterface`. Because
`Server::dispatch()` already routes *any* `JsonApiOperationInterface` through
`validateStrictQueryParameters()` + `fireServing()` (the request-wide serving/authz gate)
into the single `OperationHandlerInterface::handle()`, **no `dispatch()` change is needed** —
the action inherits the serving gate, strict-query validation, and the handler contract for
free.

```php
// core src/Operation/CustomActionOperation.php
final readonly class CustomActionOperation implements JsonApiOperationInterface
{
    public function __construct(
        private Target $target,                 // type + optional id (resource scope ⇔ hasId())
        private QueryParameters $queryParameters,
        private OperationContext $context,
        private string $action,                 // the {action} segment
        private string $method,                 // the HTTP method used
        private ?JsonApiRequestInterface $body = null,
    ) {}
    public function target(): Target { return $this->target; }
    public function queryParameters(): QueryParameters { return $this->queryParameters; }
    public function context(): OperationContext { return $this->context; }
    public function action(): string { return $this->action; }
    public function method(): string { return $this->method; }
    public function body(): ?JsonApiRequestInterface { return $this->body; }
}
```

**Bundle constructs it** (core's `OperationFactory::fromRequest()` stays CRUD-pure). The
bundle's `RequestListener` detects an action route (a `_jsonapi_action` route default),
parses the body per the action's input mode, builds the `CustomActionOperation`, and calls
`Server::dispatch()`.

The single bundle handler (`CrudOperationHandler`, the global handler, still decoration-
overridable per ADR 0028) gains **one arm** delegating to an injected, optional
`ActionInvoker`:

```php
$operation instanceof CustomActionOperation
    => $this->actions?->invoke($operation) ?? ErrorResponse::fromException(new ResourceNotFound()),
```

`ActionInvoker::invoke(CustomActionOperation)`:
1. resolve the `ActionDescriptor` + the `ActionHandlerInterface` service from the
   `ActionRegistry` by the composite key `(server, type, scope, action)`; **404** if none
   (also covers a method that matched no route → Symfony already 405s at routing);
2. resource scope: fetch the entity via `DataProviderRegistry::forType($type)->fetchOne($type, $id)`; **404** if null;
3. Document input: resolve + hydrate + Validator-bridge-validate the `input` object (§3);
4. dispatch `BeforeActionEvent` (authz gate + hooks — §6);
5. build the `ActionContext` and call `$handler->handle($context)`;
6. dispatch `AfterActionEvent`; return the response value object.

## 6. Authz + lifecycle

Two layers, both reused:
- The request-wide **serving gate** (`ServingEvent`) fires inside `Server::dispatch()` — so
  any global authz already applies to actions with no extra wiring.
- A per-action **`security` expression** rides a new `BeforeActionEvent($type, $action,
  ?object $subject, ?string $security)` dispatched by `ActionInvoker` *after* entity
  resolution, *before* the handler. `ResourceSecuritySubscriber::onBeforeAction()` evaluates
  the expression (when present) against the subject (the entity for resource scope; `null`
  for collection scope) via the `AuthorizationCheckerInterface` + `Expression`, throwing
  `AccessDeniedException` (mapped to **403** by the existing exception listener) on deny —
  exactly mirroring `onBeforeCreate`/`onBeforeUpdate`. The expression is carried **on the
  event** (per-action, not per-type), so no `ResourceSecurityRegistry` change is needed.
- `BeforeActionEvent`/`AfterActionEvent` are also public lifecycle events
  (`ResourceLifecycleHooksInterface` consumers can subscribe), for symmetry with the CRUD
  hooks.

## 7. Routing

`JsonApiRouteLoader` emits, **before** the generic `/{uriType}/{id}` and
`/{uriType}/{id}/{relationship}` routes for each type (so the literal `-actions` segment is
never captured as an `{id}` or `{relationship}`):

- resource scope: `/{uriType}/{id}/-actions/{action}` with the declared methods;
- collection scope: `/{uriType}/-actions/{action}` with the declared methods.

Route defaults: the standard `_controller` (`JsonApiController`), `_jsonapi_type` (=> the
JSON:API type), `_jsonapi_server`, `ExceptionListener::ROUTE_MARKER`, **plus** `_jsonapi_action`
(=> the action name) and `_jsonapi_action_scope`. `{action}` is a single segment. The
per-server **action route descriptors** are assembled by `ResourceLocatorPass` (collecting
the `ACTION_TAG` services + attribute metadata) and injected into the route loader exactly
like the CRUD route descriptors.

## 8. Negotiation relaxation for Raw input (small core touch — in scope)

`Raw` input means the request `Content-Type` is not `application/vnd.api+json`. The bundle's
`RequestListener` must skip the request-body content-type assertion for raw-input actions
while keeping **response** `Accept` negotiation intact. Core gets a minimal seam:
`RequestValidator::negotiate(JsonApiRequestInterface $request, bool $requireJsonApiContentType = true)`
— actions pass `false` for `Raw` input; everything else is unchanged (default `true`).
This is the **only** other core change beyond `CustomActionOperation`, and is part of the
agreed plan (consistent with "prefer fixing core pre-1.0"). If a cleaner core seam is
needed, that is a GO/NO-GO — raise it, do not improvise a workaround.

## 9. File plan

### Core (`/Users/gregory.haddow/Sites/json-api`) — branch `feat/custom-actions`
- NEW `src/Operation/CustomActionOperation.php` (§5).
- EDIT `src/Negotiation/RequestValidator.php` — `negotiate()` gains `bool $requireJsonApiContentType = true` (§8).
- NEW `docs/adr/0069-first-class-custom-action-operation.md`.
- Tests: operation shape + the negotiation relaxation (unit).

### Bundle (`/Users/gregory.haddow/Sites/json-api-symfony`) — branch `feat/custom-actions`
- NEW `src/Attribute/AsJsonApiAction.php`.
- NEW `src/Action/ActionScope.php`, `ActionInput.php` (enums).
- NEW `src/Action/ActionHandlerInterface.php`, `ActionInputFactoryInterface.php`.
- NEW `src/Action/ActionContext.php`, `ActionDescriptor.php`, `ActionRegistry.php`, `ActionInvoker.php`.
- NEW `src/Event/BeforeActionEvent.php`, `AfterActionEvent.php`.
- EDIT `src/JsonApiBundle.php` — `ACTION_TAG` const + `AsJsonApiAction` autoconfiguration.
- EDIT `src/DependencyInjection/Compiler/ResourceLocatorPass.php` — collect `ACTION_TAG`; build the `ActionRegistry` (handler service-locator + descriptors) and per-server action route descriptors.
- EDIT `src/Routing/JsonApiRouteLoader.php` — emit action routes first (§7).
- EDIT `src/EventListener/RequestListener.php` — action branch (§5/§8).
- EDIT `src/Operation/CrudOperationHandler.php` — `CustomActionOperation` arm + optional `?ActionInvoker $actions`; widen return union with `MetaResponse`.
- EDIT `src/Security/ResourceSecuritySubscriber.php` — `onBeforeAction` (§6).
- EDIT `src/Server/ServerFactory.php` (+ DI services config) — wire `ActionInvoker`/`ActionRegistry`.
- NEW `docs/adr/0076-custom-non-crud-actions.md`.
- NEW `docs/actions.md` (+ mkdocs nav entry).
- Example (music-catalog): a resource-scope `Document` action, a `Raw`-input upload action, and a collection-scope action; with tests.

## 10. Acceptance

- `CustomActionConformanceTestCase` run on **both** providers (in-memory + Doctrine-sqlite):
  resource-scope Document action returning the mount resource; collection-scope action;
  Raw-input action (blob/file upload) returning 204; custom `inputType`/`outputType`
  (decoupled command in / bespoke doc out); `security` deny → 403; serving-gate deny → 403;
  unknown action → 404; entity-not-found (resource scope) → 404; method mismatch → 405;
  route ordering (an action is not shadowed by `/{type}/{id}` nor a relationship route).
- Green **PHPStan L9 + PER-CS 2.0 + the full suite** on both repos.
- Example app: the new actions covered by a functional test in the example's suite.
