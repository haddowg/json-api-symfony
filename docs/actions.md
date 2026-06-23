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
    outputType: null,               // serializer type for the response doc; defaults to `type`
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
#[AsJsonApiAction(type: 'playlists', path: 'archive')]
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
a binary blob. The request content-type assertion is relaxed and **nothing** is
parsed or validated; the handler reads the request directly:

```php
#[AsJsonApiAction(
    type: 'tracks',
    path: 'waveform',
    input: ActionInput::Raw,
)]
final class UploadWaveform implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        $track  = $context->entity();
        $upload = $context->request()->getUploadedFile('file');   // raw multipart upload
        // …store the file, attach it to $track…

        return $context->noContent();
    }
}
```

```http
POST /tracks/7/-actions/waveform
Content-Type: multipart/form-data; boundary=…
```

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
    outputType: 'publish-receipts',     // render the response as a PublishReceipt document
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
| `DataResponse` | a JSON:API document, through the `outputType` serializer | `200` |
| `MetaResponse` | a top-level `meta`-only JSON:API document | `200` |
| `NoContentResponse` | empty body | `204` |
| `ErrorResponse` | a JSON:API error document | the error's status |

Because the response flows through the existing [`ViewListener`](lifecycle.md),
links, the `jsonapi` object, content negotiation, and error rendering are all
reused unchanged — an action document is indistinguishable from a CRUD document on
the wire.

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
| `data($data)` | a `DataResponse` pre-wired to the `outputType` serializer |
| `meta($array)` | a `MetaResponse` |
| `noContent()` | a `NoContentResponse` (`204`) |

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

> The `security` expression layer activates only when `symfony/security-core` and
> `symfony/expression-language` are installed and a firewall is configured — the
> same conditions as [declarative authorization](authorization.md). Without them a
> declared `security` is inert.

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
      "self": "https://example.com/articles/42",
      "publish": "https://example.com/articles/42/-actions/publish"
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

The contribution applies to **every** rendered resource of the type, primary or
included — so `GET /comments/1?include=article` carries the `publish` link on the
included `articles` member too.

> `asLink` is **resource scope only**: a collection-scope action has no resource to
> hang a link on, so `asLink: true` on a `ActionScope::Collection` action is a
> build-time error. The security-aware visibility uses the same `symfony/security-core`
> + firewall wiring as the `security` gate; with no firewall configured, a
> `security`-gated action's link is suppressed (fail-closed — the gate would deny at
> invocation too).

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
