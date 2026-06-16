# The request lifecycle: kernel listeners over `Server::dispatch()`

This is the central mental model for the bundle. The core library ships a PSR-15
middleware chain that turns a request into a JSON:API response (see core
[architecture](https://github.com/haddowg/json-api/blob/main/docs/architecture.md)
and [middleware](https://github.com/haddowg/json-api/blob/main/docs/middleware.md)).
This bundle **does not run that chain.** It drives the same lifecycle *logic* —
negotiation, validation, operation construction, rendering — directly from three
native Symfony kernel listeners. The payoff: your JSON:API endpoints are ordinary
Symfony endpoints. The profiler wraps them, the firewall guards them, logging sees
them, and the route appears in `debug:router` like any other — because there is a
real route, a real controller, and real kernel events, not a catch-all that
swallows the request before Symfony's machinery runs.

If you only read one thing: a JSON:API request flows
**`kernel.request` → `JsonApiController` → `kernel.view`**, and any failure is
caught on **`kernel.exception`**. The three listeners hand off through request
attributes; the controller is a no-op pass-through that exists only because
HttpKernel insists every route resolve to one.

## The three listeners

| Listener | Event | Priority | What it does |
| --- | --- | --- | --- |
| [`RequestListener`](../src/EventListener/RequestListener.php) | `kernel.request` | **4** | Resolves the target + server, negotiates, validates, dispatches, stashes the response VO |
| [`JsonApiController`](../src/Controller/JsonApiController.php) | (controller) | — | Returns the stashed response VO |
| [`ViewListener`](../src/EventListener/ViewListener.php) | `kernel.view` | default | Renders the VO to an HttpFoundation response |
| [`ExceptionListener`](../src/EventListener/ExceptionListener.php) | `kernel.exception` | **128** | Renders any failure as a JSON:API error document (→ [errors](errors.md)) |

The priorities matter. `RequestListener` runs at **4**, *after* Symfony's
`RouterListener` (priority 32) — so by the time it runs, the route is matched and
the route defaults (`_jsonapi_type`, `_jsonapi_server`, …) are populated on the
request — *and after the Security Firewall* (priority 8), so an authenticated token
is already in the token storage when the listener dispatches the operation. That
ordering is load-bearing: the declarative-authorization layer (ADR 0043) evaluates
`is_granted()` at the lifecycle hooks the dispatch fires, so the firewall must have
authenticated first. `ExceptionListener` runs at **128**, high enough to win over
Symfony's own error handling on a JSON:API route. The error listener is the subject
of its own page; the rest of this one is the happy path.

## `RequestListener` — `kernel.request`, priority 4

This is the front of the lifecycle. It only acts on a JSON:API route — one whose
matched route carries `_jsonapi_type` — and is otherwise inert, so it never
touches the rest of your app's requests.

On a JSON:API route it:

1. **Resolves the target.** [`TargetResolver::resolveFromRequest()`](../src/Operation/TargetResolver.php)
   maps the route defaults (`_jsonapi_type`, the `{id}` and `{relationship}` path
   attributes, `_jsonapi_relationship_endpoint`) to a core
   [`Operation\Target`](https://github.com/haddowg/json-api/blob/main/docs/operations.md).
   A non-JSON:API route returns `null` here and the listener bails — this is the
   single guard that scopes the whole lifecycle.
2. **Picks the server.** It reads the `_jsonapi_server` route default and resolves
   the matching core `Server` via `ServerProvider::get(...)`, so a route emitted
   for a named server reaches that server's own `Server` instance (→
   [multi-server-and-testing](multi-server-and-testing.md)). A bare import resolves
   the implicit `default` server.
3. **Converts to PSR-7.** The Symfony `Request` is bridged to PSR-7 (Nyholm) via
   `PsrHttpFactory` and wrapped as a core `JsonApiRequest` — the idempotent
   request shape every core middleware operates on.
4. **Negotiates and validates** by calling core's `RequestValidator` directly
   (see [content negotiation & body validation](#content-negotiation-and-body-validation)
   below).
5. **Builds the operation** via core's `OperationFactory::fromRequest()` — the
   same verb × target-shape dispatch the PSR-15 adapter uses — and calls
   **`Server::dispatch($operation)`**. This is core's PSR-15-*bypassing* entry
   point: it runs the operation through the resolved handler without instantiating
   any `Middleware\*` class.
6. **Stashes the result.** It sets the returned response value object (plus the
   resolved server and the PSR request) on the request attributes and **sets no
   `Response`** — so HttpKernel continues to the controller and then `kernel.view`.

```php
// src/EventListener/RequestListener.php (elided)
$target = $this->targetResolver->resolveFromRequest($request);
if ($target === null) {
    return; // not a JSON:API route — leave the request untouched
}

$serverName = $request->attributes->get('_jsonapi_server');
$server = $this->servers->get(\is_string($serverName) ? $serverName : null);

$psrRequest = $this->psrHttpFactory->createRequest($request);
$jsonApiRequest = $psrRequest instanceof JsonApiRequestInterface
    ? $psrRequest
    : new JsonApiRequest($psrRequest);

$validator = new RequestValidator();
$validator->negotiate($jsonApiRequest);
$validator->validateQueryParams($jsonApiRequest);
// …body validation on write verbs (below)…

$operation = $this->operationFactory->fromRequest(
    $jsonApiRequest,
    $target,
    new OperationContext($server, $jsonApiRequest),
);

$response = $server->dispatch($operation);

$request->attributes->set(self::RESPONSE_ATTRIBUTE, $response);
$request->attributes->set(self::SERVER_ATTRIBUTE, $server);
$request->attributes->set(self::PSR_REQUEST_ATTRIBUTE, $jsonApiRequest);
```

### Why `dispatch()`, not `handle()`

Core's `Server::handle()` is the PSR-15 entry point — it runs the full middleware
stack (negotiation, body parsing, error catching) around the operation. This
bundle has already done negotiation and body validation natively, catches errors
on `kernel.exception`, and renders on `kernel.view` — so it calls the inner
**`Server::dispatch()`** instead, which runs only the operation. The bundle owns
the lifecycle *stages*; core owns the lifecycle *logic* inside each stage. That
split is the whole design.

## `JsonApiController` — the pass-through

The route resolves to [`JsonApiController`](../src/Controller/JsonApiController.php),
which does nothing but return the response VO the listener already stashed:

```php
// src/Controller/JsonApiController.php
public function __invoke(Request $request): AbstractResponse
{
    $response = $request->attributes->get(RequestListener::RESPONSE_ATTRIBUTE);

    if (!$response instanceof AbstractResponse) {
        throw new \LogicException('The JSON:API request listener did not produce a response for this route.');
    }

    return $response;
}
```

It exists only because HttpKernel requires every matched route to resolve to a
controller. Keeping it a no-op preserves the clean request → dispatch → view →
render split. The `LogicException` is a defensive guard: if you reach the
controller without a stashed VO, the listener didn't run (a wiring fault, never a
client error).

Because the controller returns a non-`Response`, HttpKernel raises a
`kernel.view` event — which is where the response is actually built.

## `ViewListener` — `kernel.view`

The view listener takes the stashed core response VO and renders it. Core's
response value objects (`DataResponse`, `NoContentResponse`, `RelatedResponse`,
…; see core
[responses](https://github.com/haddowg/json-api/blob/main/docs/responses.md))
carry a **serializer-free render seam**: `AbstractResponse::toPsrResponse()` builds
the JSON:API document array and `json_encode`s it with `JSON_THROW_ON_ERROR`
inline. The listener calls it, then bridges PSR-7 back to HttpFoundation:

```php
// src/EventListener/ViewListener.php (elided)
$response = $request->attributes->get(RequestListener::RESPONSE_ATTRIBUTE);
if (!$response instanceof AbstractResponse) {
    return;
}

$psrResponse = $response->toPsrResponse($server, $psrRequest);

$event->setResponse($this->httpFoundationFactory->createResponse($psrResponse));
```

This is the stage where the spec-compliant body and the
`Content-Type: application/vnd.api+json` header reach HttpFoundation. The
response's HTTP status comes from the VO itself — a `DataResponse` for a create
carries `201`, a `NoContentResponse` carries `204`, and so on (the handler that
built the VO owns that decision; → [data-layer](data-layer.md)).

## The request-attribute contract

The three stages communicate purely through request attributes. If you write a
listener that needs to observe a JSON:API request mid-flight, these are the keys
(all defined as constants on `RequestListener`):

| Attribute | Constant | Carries |
| --- | --- | --- |
| `_jsonapi_response` | `RequestListener::RESPONSE_ATTRIBUTE` | The core `AbstractResponse` VO produced by `dispatch()` |
| `_jsonapi_resolved_server` | `RequestListener::SERVER_ATTRIBUTE` | The resolved core `Server` |
| `_jsonapi_psr_request` | `RequestListener::PSR_REQUEST_ATTRIBUTE` | The PSR-7 `JsonApiRequest` |

The view listener reads all three to render. A custom `kernel.view` listener at a
higher priority could intercept or transform the VO before it renders; a custom
`kernel.response` listener could decorate the rendered HttpFoundation response (to
add a header, say) the same way it would on any Symfony endpoint.

<a id="content-negotiation-and-body-validation"></a>
## Content negotiation and body validation

Negotiation and body validation run **in the request listener**, before dispatch,
by calling core's `RequestValidator` directly. The bundle owns the *call sites*;
core owns the *rules* (see core
[content-negotiation](https://github.com/haddowg/json-api/blob/main/docs/content-negotiation.md)).
Two checks run on **every** JSON:API request, in order; write verbs that carry a
body add up to three more (`validateJsonBody`, `validateTopLevelMembers`, and —
when enabled — `validateSchema`, all detailed under
[which verbs carry a body](#which-verbs-carry-a-body) below):

| Call | When | Enforces | On failure |
| --- | --- | --- | --- |
| `RequestValidator::negotiate()` | every request | The `Content-Type`/`Accept` media-type rules and extension support | `415` (unsupported `ext` on Content-Type), `406` (on Accept) |
| `validateQueryParams()` | every request | Well-formed JSON:API query parameters | `400` |
| `validateJsonBody()` | write verbs with a body | The request body is well-formed JSON | `400` |

The bundle adds **no** default `Accept` or `Content-Type`. The client must send
the JSON:API media type — core enforces it, and the bundle does not paper over a
missing header. (Every example test issues requests with
`Accept: application/vnd.api+json`, and a `Content-Type: application/vnd.api+json`
on a write; see [`JsonApiFunctionalTestCase::handle()`](../tests/Functional/JsonApiFunctionalTestCase.php).)

### Which verbs carry a body

Body validation only runs when the request carries one, and the bundle decides
that explicitly rather than inspecting the body:

```php
// src/EventListener/RequestListener.php (elided)
$method = $jsonApiRequest->getMethod();
$carriesBody = \in_array($method, ['POST', 'PATCH'], true)
    || ($method === 'DELETE' && $target->isRelationshipEndpoint);
if ($carriesBody) {
    $validator->validateJsonBody($jsonApiRequest);

    if ($target->isRelationshipEndpoint === false) {
        $validator->validateTopLevelMembers($jsonApiRequest);
    }

    $this->validateSchema($jsonApiRequest);
}
```

- **POST / PATCH** always carry a body.
- A **resource `DELETE`** (`DELETE /{type}/{id}`) carries no body.
- A **relationship-endpoint `DELETE`** (`DELETE …/relationships/{rel}`) *does* — it
  carries the `{data:[…]}` linkage to remove — so it is validated too.

There is one further subtlety. Core's `validateTopLevelMembers()` enforces a
*resource-document* rule: the top-level `data` must be present and an object. But a
**relationship-endpoint** body's `data` is *linkage* — legitimately `null` (clear a
to-one) or `[]` (clear a to-many), both of which that rule would wrongly reject as
"missing". So the bundle **skips** `validateTopLevelMembers()` for relationship
writes; the exact linkage shape is instead validated by core's relationship-linkage
parser when the handler reads it (→ [relationships](relationships.md)).

### Where these failures surface

Each of these is thrown as a core exception and rendered by the
`ExceptionListener` as a JSON:API error document — so a malformed body produces a
clean `400`, not a stack trace. The example app's `ErrorHandlingTest` witnesses
exactly this: a `POST /playlists` with a non-JSON body renders a route-scoped
`400` document with code `REQUEST_BODY_INVALID_JSON`, rejected *before* hydration —
"content negotiation owns this, not the handler" (see
[`ErrorHandlingTest`](../examples/music-catalog-symfony/tests/ErrorHandlingTest.php)).
The full error-mapping story is on the [errors](errors.md) page.

### The optional structural linter

When you enable `json_api.schema_validation` (default off; requires
`opis/json-schema`), an additional **structural** linter runs at this same call
site — `RequestListener::validateSchema()` validates the parsed write body against
the JSON:API JSON Schema and throws a `400` on a structural defect. This is
distinct from the semantic Symfony Validator bridge (which renders `422` for
values that violate your constraints). The "is this a well-formed JSON:API
document?" check (400) and the "do the values satisfy the constraints?" check (422)
are separate stages — see [validation](validation.md).

## What just happened (the three getting-started outcomes)

The three outcomes from [getting-started](getting-started.md) map cleanly onto the
stages above:

- **`GET /albums` → `200`** — the router matches `jsonapi.albums.index`,
  `RequestListener` negotiates, builds a fetch-collection operation, `dispatch()`
  returns a `DataResponse`, and `ViewListener` renders the collection.
- **`POST /playlists` → `201` + `Location`** — POST carries a body, so
  `validateJsonBody()` + `validateTopLevelMembers()` run; the handler persists and
  returns a `201` `DataResponse` whose VO carries the `Location`, rendered by
  `ViewListener`.
- **`GET /albums/999` → `404`** — the show route *exists*, so the request reaches
  the handler; the provider's null fetch becomes a core `404`, caught on
  `kernel.exception` and rendered as a JSON:API error document (not a bare Symfony
  404, because the route is JSON:API-scoped).

## Next / see also

- [lifecycle-hooks](lifecycle-hooks.md) — the author seams *into* this flow:
  per-operation before/after hooks (and the server-level `serving` gate) as Symfony
  events or overridable resource methods, for authz, delete-guards, audit, and
  custom-action shaping.
- [errors](errors.md) — the `kernel.exception` listener, the exception→status
  mapping, and debug gating.
- [routing](routing.md) — how a discovered type becomes the routes (and the route
  defaults: `_jsonapi_type`, `_jsonapi_server`, `_jsonapi`) the lifecycle reads.
- [data-layer](data-layer.md) — what `Server::dispatch()` runs: the
  `CrudOperationHandler` over the provider/persister SPI, and the response VOs it
  builds.
- [multi-server-and-testing](multi-server-and-testing.md) — how `_jsonapi_server`
  resolves to a per-server `Server`.
- Core [architecture](https://github.com/haddowg/json-api/blob/main/docs/architecture.md)
  and [middleware](https://github.com/haddowg/json-api/blob/main/docs/middleware.md)
  (the PSR-15 lifecycle this bundle replaces with listeners),
  [content-negotiation](https://github.com/haddowg/json-api/blob/main/docs/content-negotiation.md),
  and [responses](https://github.com/haddowg/json-api/blob/main/docs/responses.md).
