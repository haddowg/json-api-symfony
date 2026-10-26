# Custom (non-CRUD) actions

Some operations don't fit the CRUD verbs: *publish* an article, *archive* a
playlist, upload an avatar, run a one-off command over a collection. The bundle
gives these a first-class home — author-defined **custom actions** that hang off a
resource type under a reserved `-actions` URL segment, dispatch through the same
core pipeline as every CRUD operation (so they inherit query validation, the
serving gate, and error rendering for free), and can associate a **custom
request/response document** while staying valid JSON:API.

An action is a **standalone capability**: a class implementing
`ActionHandlerInterface`, declared with `#[AsJsonApiAction]` and discovered by
autoconfiguration — exactly the standalone-serializer/hydrator pattern from
[capability composition](capability-composition.md). There is no
`AbstractResource` sugar; an action is its own small unit that names the type it
mounts on.

## The URL structure

Every action lives under a fixed, reserved `-actions` segment, at one of two
scopes:

| Scope | Path | The handler gets |
|-------|------|------------------|
| **Resource** | `POST /{uriType}/{id}/-actions/{action}` | the `{id}` resolved to an entity (via the type's [`DataProvider`](data-layer.md)) before the handler runs |
| **Collection** | `POST /{uriType}/-actions/{action}` | no entity (`null`) — the action operates on the type as a whole |

`{action}` is a single path segment naming one action. `-actions` is a safe
reserved literal: JSON:API member names cannot begin with a dash, so it can never
collide with a resource id or a relationship name. The [route loader](routing.md)
emits action routes **before** the generic `/{uriType}/{id}` and
`/{uriType}/{id}/{relationship}` routes, so the literal is never captured as an
`{id}` or `{relationship}` — an action is never shadowed by a CRUD or relationship
route.

`POST` is the default method, but an action may declare any of `GET` / `POST` /
`PATCH` / `PUT` / `DELETE`. A request whose method matches no declared action route
gets Symfony's standard `405`.

## Declaring an action

```php
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;

#[AsJsonApiAction(
    type: 'articles',               // mount type: the {uriType} segment + the DEFAULT serializer/hydrator
    path: 'publish',                // the {action} segment
    methods: ['POST'],              // default ['POST']
    scope: ActionScope::Resource,   // Resource (default) | Collection
    input: ActionInput::None,       // None (default) | Document | Raw
    inputType: null,                // Document mode only: hydrator type for the request doc; defaults to `type`
    responds: [new ActionResource('articles')],   // the OpenAPI success response set; omitted = a 200 mount-type document
    server: null,                   // multi-server assignment; defaults to the implicit `default`
    security: "is_granted('PUBLISH', subject)",   // optional authz expression (see Authorization)
    name: null,                     // optional route-name override
    asLink: false,                  // expose as a security-aware `links` member (resource scope only)
)]
final class PublishArticle implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse|MetaResponse|NoContentResponse|ErrorResponse
    {
        $article = $context->entity();              // resolved Article (resource scope)
        $article->publish();
        // …persist the change…

        return $context->data($article);            // renders through the outputType serializer
    }
}
```

The two scope/input enums:

- `ActionScope` — `Resource` | `Collection`.
- `ActionInput` — `None` | `Document` | `Raw`.

That's the whole declaration surface. Autoconfiguration tags the class, the
bundle's compiler pass assembles the per-server action route descriptors, and the
[route loader](routing.md) emits the route — no manual routing, no controller.

## The three input modes

CRUD writes always take a JSON:API document; actions span a wider range, so the
input contract is **permissive** and chosen per action with `input:`.

| Mode | Request handling | What the handler receives |
|------|------------------|---------------------------|
| **`None`** (default) | no body read; request `Content-Type` not required | `$context->input()` is `null` |
| **`Document`** | parsed + structurally validated as JSON:API (negotiate, JSON decode, top-level members, optional [opis schema](validation.md)) **and** semantically validated through the [Validator bridge](validation.md) against `inputType`'s constraints | `$context->input()` is the **hydrated object** of `inputType` |
| **`Raw`** (escape hatch) | request `Content-Type` negotiation **relaxed** (a `multipart/form-data` upload is not `application/vnd.api+json`); no JSON-API body parsing or validation | `$context->request()` exposes the raw body + uploaded files; `$context->input()` is `null` |

In every mode the **response** `Accept` negotiation stays intact — only the
**request** body content-type assertion is relaxed for `Raw`.

### `None` — no body

The simplest action. A resource-scope `None` action is the classic "do a thing to
this entity" verb:

```php
#[AsJsonApiAction(type: 'playlists', path: 'archive', responds: [new NoContent()])]
final class ArchivePlaylist implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        $playlist = $context->entity();
        $playlist->archive();
        // …persist…

        return $context->noContent();   // 204
    }
}
```

The handler returns a `204`, so it declares `responds: [new NoContent()]` — the generated
OpenAPI document then advertises a `204` response instead of a `200` body (see
[The response contract](#the-response-contract) below).

```http
POST /playlists/42/-actions/archive
```

### `Document` — a JSON:API body, hydrated and validated

`Document` mode runs the full write pipeline on the body: it is parsed,
structurally validated, semantically validated through the [Validator
bridge](validation.md) against `inputType`'s constraints, and **hydrated to an
object**. By default `inputType` is the mount `type`, so the action receives a
hydrated instance of the mount type:

```php
#[AsJsonApiAction(type: 'articles', path: 'publish', input: ActionInput::Document)]
final class PublishArticle implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $article = $context->entity();      // the existing entity (resource scope)
        $input   = $context->input();       // a hydrated Article from the request body

        // input and entity are INDEPENDENT — apply input onto entity yourself:
        $article->setStatus($input->getStatus());
        $article->setPublishedAt($input->getPublishedAt());
        // …persist…

        return $context->data($article);
    }
}
```

> **No implicit merge.** The hydrated `input` and the resolved `entity` are kept
> **independent** — a resource-scope `Document` action reads both and applies one
> onto the other in the handler. This is deliberate: predictable, no magic merge.

**Where the input object comes from.** For `Document` mode the bundle resolves a
fresh input object and hydrates the body into it:

1. if the handler also implements **`ActionInputFactoryInterface`**
   (`newInput(JsonApiRequestInterface $body): object`), that object is used — this
   is how a **bespoke command DTO** backed only by a serializer/hydrator pair (no
   persister) supplies its blank instance;
2. otherwise the bundle instantiates via the `inputType`'s persister — the common
   case where `inputType` defaults to the mount `type`.

### `Raw` — a non-JSON:API body (uploads, blobs)

`Raw` is the escape hatch for bodies that aren't JSON:API — a file upload, a CSV,
a binary blob. **Nothing** is parsed or validated; the handler reads the request
directly. With no `accepts` list, a `Raw` action takes a body of any media type except
the three a browser can send cross-origin without a preflight: `multipart/form-data`,
`application/x-www-form-urlencoded` and `text/plain`. An upload sent as a form must
therefore list `multipart/form-data` in `accepts`:

```php
#[AsJsonApiAction(
    type: 'tracks',
    path: 'waveform',
    input: ActionInput::Raw,
    accepts: ['multipart/form-data'],
    responds: [new NoContent()],
)]
final class UploadWaveform implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        $track  = $context->entity();
        $upload = $context->request()->getUploadedFiles()['file'] ?? null;
        // …store the file, attach it to $track…

        return $context->noContent();
    }
}
```

```http
POST /tracks/7/-actions/waveform
Content-Type: multipart/form-data; boundary=…
```

### Accepting several request media types (`accepts`)

`accepts` lists the request media types an action takes, and each one can be read in
its own mode:

```php
#[AsJsonApiAction(
    type: 'tracks',
    path: 'import',
    scope: ActionScope::Collection,
    input: ActionInput::Raw,
    accepts: [
        'text/csv',                                             // read in the action's mode, Raw
        'image/*',                                              // any image type
        'application/vnd.api+json' => ActionInput::Document,    // a JSON:API document, hydrated
        new AcceptedBody('application/json', schema: TrackRows::class),
    ],
)]
```

An entry is a media type read in the action's own `input` mode, a media type key
mapped to the `ActionInput` it is read in, or core's `AcceptedBody`, which also gives
the OpenAPI document the body's schema. Wildcards such as `image/*` match any subtype,
and parameters such as `charset` or a multipart `boundary` never stop a match. The three
cross-origin form types match only when listed by name, never through a wildcard.

A body whose `Content-Type` matches nothing listed is a `415`. The handler reads the
matched type from `$context->requestMediaType()` (`image/png` for an upload matched by
`image/*`), and a body read as a `Document` arrives hydrated on `$context->input()`. In
the OpenAPI document each entry becomes its own `requestBody.content` member, and the
operation lists the `415`.

### The request `Content-Type`

Every `POST` must send a `Content-Type`, even one with no body, and so must a `PATCH`,
`PUT` or `DELETE` that has a body. For CRUD writes, Atomic Operations and `None` or
`Document` actions it must be `application/vnd.api+json`; anything else is a `415`
(core ADR 0172). A bodyless `POST` sent as `application/vnd.api+json` passes any action,
whatever its `accepts`, and the handler sees no body: clients send that type on every
`POST`, and it cannot be sent cross-origin without a preflight. A bodyless `PATCH`,
`PUT` or `DELETE` needs no `Content-Type`.

## Custom input and output types

By default an action speaks the mount type on both sides. But the request and
response documents are **decoupled from the mount type**: `inputType` and
`outputType` may each point at any other registered type — including a
**standalone serializer/hydrator pair** (a [capability-composed](capability-composition.md)
type with no endpoints of its own). So an action can accept a bespoke *command*
document and return a bespoke *result* document while both stay valid JSON:API.

```php
#[AsJsonApiAction(
    type: 'articles',                   // mounts under /articles, resolves the Article entity
    path: 'publish',
    input: ActionInput::Document,
    inputType: 'publish-commands',      // hydrate the body into a PublishCommand DTO
    responds: [new ActionResource('publish-receipts')],   // render the response as a PublishReceipt document
)]
final class PublishArticle implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $article = $context->entity();          // the Article (mount type)
        $command = $context->input();           // a hydrated PublishCommand (inputType)

        $receipt = $article->publish($command->scheduledFor());

        return $context->data($receipt);        // serialized as a publish-receipts document (outputType)
    }
}
```

A bespoke command type needs only a serializer + hydrator pair (no provider, no
persister) — see [capability composition](capability-composition.md) for
registering a standalone type. When such a type has no persister to instantiate
its blank object, implement `ActionInputFactoryInterface::newInput()` on the
handler to supply the instance.

## The response contract

The output side is **strict**: `handle()` returns a core **response value object**,
never a raw HTTP response. The choices:

| Return | Renders | Status |
|--------|---------|--------|
| `DataResponse` | a JSON:API document, through the response serializer | `200` |
| `MetaResponse` | a top-level `meta`-only JSON:API document | `200` |
| `NoContentResponse` | empty body | `204` |
| `AcceptedResponse` | an async accept — a job document + `Content-Location` / `Retry-After` | `202` |
| `SeeOtherResponse` | an async-completion redirect — a `Location` header, no body | `303` |
| `ErrorResponse` | a JSON:API error document | the error's status |

Because the response flows through the existing [`ViewListener`](lifecycle.md),
links, the `jsonapi` object, content negotiation, and error rendering are all
reused unchanged — an action document is indistinguishable from a CRUD document on
the wire.

### Advertising the output in OpenAPI

The runtime response is whatever the handler returns; the **generated OpenAPI
document** advertises the success response set, declared on the attribute so it
matches. `responds` takes one response object or a **list** of them, from
`haddowg\JsonApi\OpenApi\Metadata`:

| Declaration | Advertised success response |
|-------------|-----------------------------|
| (omitted) | a `200` with the mount `type`'s document schema |
| `responds: [new ActionResource('receipts')]` | a `200` with the named type's document schema (any registered type) |
| `responds: [new MetaResult()]` | a `200` referencing the shared `MetaDocument` component (a meta-only document, no `data`) |
| `responds: [new NoContent()]` | a `204 No Content` |
| `responds: [new Accepted('jobs')]` | a `202 Accepted` — the `jobs` type's document + `Content-Location` + `Retry-After` (an async accept) |
| `responds: [new SeeOther()]` | a `303 See Other` — a `Location` header, no body (an async-completion redirect) |

An action that can answer more than one way lists them — a poll endpoint that returns
`202` while the work runs and `303` once it is done declares
`responds: [new Accepted('jobs'), new SeeOther()]`. The set is validated at
declaration time (`ActionResponses::validate()`), so an impossible set (duplicate
statuses, two job types) fails the build. Omitted, `responds` defaults to a `200`
document of the mount type.

`responds` exists because the projector cannot read the handler's body. To stop the
declaration drifting from the handler, the bundle **guards at compile time**: a
handler whose `handle()` return type is narrowed to exactly `NoContentResponse` must
declare a `new NoContent()`, and one narrowed to exactly `MetaResponse` must declare a
`new MetaResult()` — otherwise the container fails to compile with an explanatory
error. A handler that keeps the interface's union return type opts out of the guard.

An action can also describe its operation. `summary` and `description` on the
attribute become the operation's `summary` and `description`; left out, neither key is
written:

```php
#[AsJsonApiAction(
    type: 'products',
    path: 'recalculate-prices',
    scope: ActionScope::Collection,
    summary: 'Recalculate every product price',
    description: 'Reprices the whole catalogue from the current cost and margin rules.',
)]
```

### Raw responses (CSV, PDF, plain JSON)

An action can answer with a body that is not JSON:API by declaring a core `RawResult`
per media type in `responds`, beside or instead of its JSON:API response. The request's
`Accept` header picks one before the handler runs, and `$context->responseMediaType()`
says which; `$context->raw()` builds the response in that type:

```php
#[AsJsonApiAction(
    type: 'albums',
    path: 'export',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [
        new MetaResult(),
        new RawResult('text/csv', schema: ['type' => 'string'], headers: ['Content-Disposition' => 'Names the downloaded file.']),
        new RawResult('application/pdf', binary: true),
        new RawResult('application/json', schema: AlbumCatalogueStats::class),
    ],
)]
final class ExportAlbums implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse|RawResponse
    {
        return match ($context->responseMediaType()) {
            'text/csv' => $context->raw($csv, headers: ['Content-Disposition' => 'attachment; filename="albums.csv"']),
            'application/pdf' => $context->raw($pdfStream),
            'application/json' => $context->raw(\json_encode($counts)),
            default => $context->meta(['albums' => $counts]),
        };
    }
}
```

A tie in the `Accept` weights goes to JSON:API, so `*/*` or no `Accept` still gets the
document. An `Accept` that matches none of the declared types is a `406`. A body can be
a string, a stream resource or a PSR-7 stream; a stream is sent with Symfony's
`StreamedResponse`. Every raw response carries `Vary: Accept`, and the type's cache and
deprecation headers apply as they do to a document. Answering in a media type the request
did not negotiate is a `500`, and an error is always a JSON:API document, whatever the
`Accept` picked. In the OpenAPI document each raw result is a media type under its
status, and a `DescribesRawBody` schema class becomes a component named by its short
class name.

### The `ActionContext`

The handler is handed an `ActionContext` so it never has to thread the server. It
exposes both the resolved request state and pre-wired response factories:

| Member | Returns |
|--------|---------|
| `entity()` | the resolved entity (resource scope) or `null` (collection scope) |
| `input()` | the hydrated input (`Document` mode) or `null` |
| `request()` | the JSON:API request — always; the raw body + uploaded files for `Raw` mode |
| `queryParameters()` | the parsed, strict-validated query parameters |
| `serializer()` | the `outputType` serializer |
| `server()` | the resolving server |
| `data($data)` | a `DataResponse` pre-wired to the response serializer |
| `meta($array)` | a `MetaResponse` |
| `noContent()` | a `NoContentResponse` (`204`) |
| `accepted($url)` | an `AcceptedResponse` (`202`) — an async accept pointing at the poll URL (chain `->withRetryAfter()`) |
| `seeOther($url)` | a `SeeOtherResponse` (`303`) — an async-completion redirect to the produced resource |

The `data()` / `meta()` / `noContent()` factories are the ergonomic path; reach for
the raw response value objects directly only when you need to set a status or
header the factories don't.

## Authorization

Actions are authorized by **two reused layers** — you write no special wiring for
either:

1. **The request-wide serving gate.** The `ServingEvent` fires inside
   `Server::dispatch()` for every operation, actions included, so any global
   authorization you already have applies automatically.
2. **A per-action `security` expression.** Declare a Symfony Security
   [expression](https://symfony.com/doc/current/security/expressions.html) on the
   attribute and the bundle evaluates it **after** entity resolution and **before**
   the handler, denying with a JSON:API `403` (or `401` when unauthenticated):

```php
#[AsJsonApiAction(
    type: 'articles',
    path: 'publish',
    security: "is_granted('PUBLISH', subject)",
)]
```

The `subject` variable is the **resolved entity** for a resource-scope action and
`null` for a collection-scope action — so `is_granted('PUBLISH', subject)`
delegates straight to an ordinary Symfony
[Voter](https://symfony.com/doc/current/security/voters.html), exactly as
[`securityUpdate`](authorization.md) does for CRUD. The expression rides a
`BeforeActionEvent`, so it is evaluated per action (not per type) and needs no
resource-level registration.

> A `security` expression needs `symfony/security-core`,
> `symfony/expression-language` and a configured firewall, the same as
> [declarative authorization](authorization.md). Without them the container build fails
> and names the action.

For authorization an expression can't capture — a multi-entity rule, a
data-dependent check — subscribe to the `BeforeActionEvent` directly and throw a
`JsonApiExceptionInterface` (see [lifecycle hooks](lifecycle-hooks.md)).

## Exposing an action as a resource link (`asLink`)

Set `asLink: true` and the action's URL is published as a `links` member on every
rendered resource of its mount type — keyed by the action's `path` — so a client
discovers the action straight from the resource it acts on, with no out-of-band
knowledge of the URL structure:

```php
#[AsJsonApiAction(
    type: 'articles',
    path: 'publish',
    security: "is_granted('PUBLISH', subject)",
    asLink: true,
)]
```

```jsonc
// GET /articles/42
{
  "data": {
    "type": "articles",
    "id": "42",
    "attributes": { "...": "..." },
    "links": {
      "self": {
        "href": "https://example.com/articles/42",
        "describedby": "/docs.json#/paths/~1articles~1%7Bid%7D/get"
      },
      "publish": {
        "href": "https://example.com/articles/42/-actions/publish",
        "describedby": "/docs.json#/paths/~1articles~1%7Bid%7D~1-actions~1publish/post"
      }
    }
  }
}
```

The link is **security-aware**. When the action declares a `security` expression,
its link is rendered **only when the current requester would pass that same gate** —
evaluated exactly as the `BeforeActionEvent` gate evaluates it at invocation — so a
client never sees a link to an action it cannot invoke (the `publish` link above is
absent for a user who lacks `PUBLISH` on article 42). An action with no `security`
always renders its link. The link is added without the resource's own
`getLinks()` having to know about it, and an author-supplied link of the same name
always wins.

The link's `describedby` names the action's operation in the served OpenAPI document,
using its first declared method, and the document lists the action under its name in
the type's resource links (see [links and `describedby`](openapi.md#links-and-describedby)).

The contribution applies to **every** rendered resource of the type, primary or
included — so `GET /comments/1?include=article` carries the `publish` link on the
included `articles` member too.

> `asLink` is **resource scope only**: a collection-scope action has no resource to
> hang a link on, so `asLink: true` on a `ActionScope::Collection` action is a
> build-time error. The security-aware visibility uses the same authorization checker
> as the `security` gate, so link visibility and invocation always agree. An action
> with a `security` expression and no firewall [fails the
> build](authorization.md#enabling-the-layer).

## Lifecycle events

`BeforeActionEvent` and `AfterActionEvent` are public [lifecycle
events](lifecycle-hooks.md), symmetric with the CRUD hooks. `BeforeActionEvent`
fires after entity resolution and before the handler (it carries the type, the
action name, the subject, and any `security` expression); `AfterActionEvent` fires
after the handler returns. Subscribe to them for cross-cutting concerns — an audit
log, a custom authorization rule, a metric.

## Error responses

Every error path renders as a JSON:API error document through the route-scoped
[`ExceptionListener`](errors.md):

| Situation | Status |
|-----------|--------|
| unknown action name | `404` |
| entity not found (resource scope) | `404` |
| method not allowed for the action | `405` (Symfony routing) |
| `security` expression denies (authenticated) | `403` |
| `security` expression denies (unauthenticated) | `401` |
| serving gate denies | `403` |
| a `Document`-mode body that fails validation | `422` (see [validation](validation.md)) |

You can also return an `ErrorResponse` from `handle()` for a domain failure the
handler detects itself — it renders with the error's own status.
