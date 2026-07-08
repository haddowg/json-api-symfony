# Route-scoped error handling

On a JSON:API route, every failure becomes a spec-compliant error document — the
`application/vnd.api+json` media type, a top-level `errors` array, a string
`status`, and a stable error `code` — whether it originated in core (an unknown
filter, a missing resource, a validation failure), in Symfony (a firewall denial,
a routing 404), or anywhere else (an unexpected `\Throwable` → 500). One listener
owns all of it.

The vocabulary of that document — the `Error` and `ErrorSource` value objects, the
`ErrorResponse`, the `InternalServerError::for()` seam, the exception catalogue and
its stable `code`s — is **core's**. See core
[errors-and-exceptions](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md).
This page documents only what the bundle adds: a route-scoped `kernel.exception`
listener, the Symfony-exception → status mapping, debug gating, and how it composes
with the firewall and logging.

## The model: a route-scoped `kernel.exception` listener

[`ExceptionListener`](../src/EventListener/ExceptionListener.php) registers on
`kernel.exception` at priority **128** — high enough to win over Symfony's own
error handling on JSON:API routes — and is **route-scoped**: it acts only when the
matched route carries the marker `_jsonapi` (`ExceptionListener::ROUTE_MARKER`).
Every route the bundle's loader emits stamps that default (see
[routing](routing.md)); nothing else does. So on any non-JSON:API route the
listener returns immediately and Symfony handles the error as usual — the bundle
never hijacks the rest of your app.

```php
public function onKernelException(ExceptionEvent $event): void
{
    $request = $event->getRequest();

    if ($request->attributes->get(self::ROUTE_MARKER) !== true) {
        return;
    }
    // … resolve the server, map the throwable, render the error document
}
```

When the marker is present, the listener resolves the request's server
(`_jsonapi_server`, defaulting to the implicit `default` — see
[multi-server-and-testing](multi-server-and-testing.md)), maps the throwable to a
core `ErrorResponse`, renders it to PSR-7 via `ErrorResponse::toPsrResponse()`, and
bridges that back to an HttpFoundation `Response` it sets on the event. No
controller, no template — the same render seam the
[lifecycle](lifecycle.md)'s view listener uses on the success path.

## The three mapping arms

`ExceptionListener::toErrorResponse()` is a three-way branch on the throwable:

| Throwable | Mapped via | Status & shape |
| --- | --- | --- |
| Core `JsonApiExceptionInterface` | `ErrorResponse::fromException($throwable)` | The exception's own `getErrors()` / `getStatusCode()` — the full core error object(s), `source` and `code` intact |
| Symfony `HttpExceptionInterface` | `ErrorResponse::fromErrors($this->httpError(...))` | A single status-keyed `Error` with a reason-phrase `title` (the bundle's `match()` table) and a debug-only `detail` |
| Anything else (`\Throwable`) | `InternalServerError::for($throwable, $debug)` | A generic `500`, byte-identical to core's own middleware 500 |

**Arm 1 — core exceptions render themselves.** Anything implementing core's
`JsonApiExceptionInterface` already knows its status and its error objects, so the
listener hands it straight to `ErrorResponse::fromException()`. This is the common
case on a JSON:API route: an unknown filter (`FILTERING_UNRECOGNIZED`, 400), an
unrecognized query-parameter family or an unknown `fields[type]` sparse-fieldset
member (`QUERY_PARAM_UNRECOGNIZED` / `FIELDSET_MEMBER_UNRECOGNIZED`, 400 — strict
query-parameter validation, on by default, see
[configuration](configuration.md#strict_query_parameters)), a missing resource
(`RESOURCE_NOT_FOUND`, 404), an unknown relationship (`RELATIONSHIP_NOT_EXISTS`,
404), a validation failure (`VALIDATION_FAILED`, 422 — see
[validation](validation.md)). The bundle adds nothing to the shape; it only
ensures the document is rendered on the JSON:API route instead of being swallowed
by framework error handling. The example app's
[`ErrorHandlingTest`](../examples/music-catalog-symfony/tests/ErrorHandlingTest.php)
witnesses several of these end to end:

```php
// GET /albums/999 — the show route matches, the provider's null fetch becomes 404
$error = $this->errorDocument('/albums/999', 404);
self::assertSame('404', $error['status'] ?? null);
self::assertSame('RESOURCE_NOT_FOUND', $error['code'] ?? null);

// GET /tracks?filter[nope]=x — an unrecognised filter
$error = $this->errorDocument('/tracks?filter[nope]=x', 400);
self::assertSame('400', $error['status'] ?? null);
self::assertSame('FILTERING_UNRECOGNIZED', $error['code'] ?? null);
self::assertSame(['parameter' => 'filter[nope]'], $error['source'] ?? null);
```

Note the 404 detail: `GET /albums/999` returns a JSON:API 404 (not a bare Symfony
404) precisely *because* the `albums` show route exists and is JSON:API-scoped — the
request reaches the handler, the provider returns `null`, and core raises the 404.
A request to a route the loader never emitted (an unknown type) 404s at the router,
before this listener is in scope, and renders as Symfony's default.

**Arm 2 — Symfony HTTP exceptions get a status-keyed error.** A Symfony
`HttpExceptionInterface` carries an HTTP status but no JSON:API error object. The
listener builds one with the bundle's own reason-phrase `title` and — only in debug
— the exception message as `detail`:

```php
private function httpError(HttpExceptionInterface $throwable): Error
{
    $status = $throwable->getStatusCode();

    return new Error(
        status: (string) $status,
        title: $this->reasonPhrase($status),
        detail: $this->debug ? $throwable->getMessage() : '',
    );
}
```

The reason-phrase table:

| Status | Title |
| --- | --- |
| 400 | `Bad Request` |
| 401 | `Unauthorized` |
| 403 | `Forbidden` |
| 404 | `Not Found` |
| 405 | `Method Not Allowed` |
| 406 | `Not Acceptable` |
| 409 | `Conflict` |
| 415 | `Unsupported Media Type` |
| 422 | `Unprocessable Entity` |
| any other `>= 500` | `Server Error` |
| anything else | `Error` |

This arm is where the firewall (401/403) and routing (404, 405) land — see the
firewall interplay below.

> Between arm 1 and arm 2, the listener consults the application-extensible
> exception mappers (below). Arm 1 always runs first: a core
> `JsonApiExceptionInterface` is **never** intercepted by a mapper or the config
> map.

## Mapping your own exceptions

A domain or third-party exception that is *not* a core `JsonApiExceptionInterface`
and *not* a Symfony `HttpExceptionInterface` falls to the generic `500` by default.
Two facets let you map it to a JSON:API error without decorating the listener.

Both are consulted **after** arm 1 and **only** for a throwable that is not a core
`JsonApiExceptionInterface` — the invariant being that **a core JSON:API exception
always renders natively and is never overridden** by a mapper or the config map.

### Facet 1 — the `json_api.exceptions` config map

For the common status-only case, map an exception class to an HTTP status in config:

```yaml
# config/packages/json_api.yaml
json_api:
    exceptions:
        App\Exception\PaymentRequired: 402
        App\Exception\TooManyWidgets: 409
```

A thrown instance renders as a JSON:API error with that `status`, the bundle's
reason-phrase `title`, and — only in debug — the exception message as `detail`
(identical in shape to the Symfony HTTP-exception arm). When a throwable matches
several mapped classes (a subclass hierarchy), the **most-specific** (most-derived)
mapped class wins.

### Facet 2 — a tagged `ExceptionMapperInterface`

For richer errors (a custom `source`, `meta`, multiple error objects, or
conditional status), implement
[`ExceptionMapperInterface`](../src/EventListener/ExceptionMapperInterface.php) — a
service returns an `ErrorResponse`, or `null` to defer to the next mapper:

```php
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApiBundle\EventListener\ExceptionMapperInterface;

final class PaymentExceptionMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $throwable): ?ErrorResponse
    {
        if (!$throwable instanceof PaymentFailed) {
            return null; // defer to the next mapper
        }

        return ErrorResponse::fromErrors(new Error(
            status: '402',
            code: 'PAYMENT_FAILED',
            title: 'Payment failed',
            detail: $throwable->reason(),
            source: ErrorSource::fromPointer('/data/attributes/card'),
        ));
    }
}
```

Any service implementing the interface is **auto-tagged**
(`json_api.exception_mapper`) — no manual tagging needed.

### Priority and ordering

The mappers are consulted in **descending tag `priority`** order (default `0`),
first non-null `ErrorResponse` wins. The bundle's config-driven mapper (the facet-1
`json_api.exceptions` map) is itself registered as a mapper at a **low priority
(`-1000`)**, so your own mappers (default `0`) are always consulted **before** the
config map. To order two of your own mappers, set an explicit tag `priority`:

```yaml
services:
    App\Exception\PaymentExceptionMapper:
        tags:
            - { name: json_api.exception_mapper, priority: 10 }
```

If no mapper returns a response, the listener falls through to arm 2 (Symfony HTTP
exceptions) and arm 3 (the generic `500`) unchanged.

**Arm 3 — everything else is a generic 500.** Any other `\Throwable` is delegated
to core's public, stateless `InternalServerError::for($throwable, $debug)` seam, so
the rendered error object is **byte-identical** to the one core's own
`ErrorHandlerMiddleware` produces — the bundle never re-implements the 500 shape.
This is verified directly in the bundle's
[`ExceptionListenerTest`](../tests/EventListener/ExceptionListenerTest.php), which
asserts the listener's output equals the seam's output for the same throwable and
debug flag. The throwable is also logged first (see logging below).

## Debug gating

Whether an error document carries internal detail is gated on `kernel.debug`,
injected as `%kernel.debug%`. With debug **off** (production), the listener
redacts:

- **Arm 2 (HTTP exceptions):** `detail` is the empty string — the exception message
  never reaches the client.
- **Arm 3 (generic 500):** core's `InternalServerError::for(..., false)` emits only
  the stable `status`/`title` (`500` / `Internal Server Error`), with no `code`,
  `detail`, or `meta`.

With debug **on**, the 500 becomes verbose: the exception class lands in
`meta.exception`, with `file`, `line`, and a `trace`, the exception code as `code`,
and the message as `detail`. The example suite boots with `kernel.debug = false`
(the production base case) and asserts no error leaks `{exception, file, line,
trace}` meta:

```php
foreach (['/albums/999', '/tracks/1/bogus', '/tracks?filter[nope]=x'] as $path) {
    $response = $this->handle($path);
    // … each error object:
    self::assertArrayNotHasKey('exception', $meta);
    self::assertArrayNotHasKey('file', $meta);
    self::assertArrayNotHasKey('line', $meta);
    self::assertArrayNotHasKey('trace', $meta);
}
```

and the bundle's listener test pins both directions of the 500:

```php
// debug off — redacted
self::assertArrayNotHasKey('detail', $error);
self::assertArrayNotHasKey('meta', $error);

// debug on — verbose
self::assertSame('leaky secret detail', $error['detail'] ?? null);
self::assertSame(\RuntimeException::class, $meta['exception'] ?? null);
```

Keep `kernel.debug` off in production, as you would on any Symfony app — the gating
is your protection against leaking secrets in an unexpected-error trace.

## Firewall interplay

Because the listener is route-scoped and a Symfony security denial is an
`HttpExceptionInterface` (401/403), a firewall exception thrown on a JSON:API route
renders as a JSON:API error document through arm 2 — not as Symfony's HTML login
redirect or access-denied page. Put a firewall in front of your JSON:API routes and
a 401/403 still comes back as `application/vnd.api+json` with a `401`/`403` status
and the matching reason-phrase title. Per-route firewall configuration and
JSON:API error rendering compose with no extra wiring — there is a real route and
real kernel events, so Symfony's security machinery runs normally (see
[lifecycle](lifecycle.md)). The example app ships no firewall, so this arm is
exercised in the bundle's own functional suite rather than the example tests.

## Logging

Unexpected throwables (arm 3 only) are logged before the 500 is rendered:

```php
$this->logger?->error($throwable->getMessage(), ['exception' => $throwable]);

return ErrorResponse::fromErrors(InternalServerError::for($throwable, $this->debug));
```

The `logger` service is injected `nullOnInvalid()`, so the dependency is optional:
if no logger is registered, the null-safe `?->` call is a no-op and the 500 still
renders correctly — but **nothing is logged**. Core exceptions (arm 1) and HTTP
exceptions (arm 2) are expected outcomes and are *not* logged; only the genuinely
unexpected `\Throwable` is. If you rely on 500s reaching your logs, ensure a logger
(monolog, or any `Psr\Log\LoggerInterface` service) is wired — it is in any standard
Symfony app.

## Which path produced your error

Two render paths exist, and the `code` member tells you which:

- **A core `code`** (`RESOURCE_NOT_FOUND`, `FILTERING_UNRECOGNIZED`,
  `VALIDATION_FAILED`, `REQUEST_BODY_INVALID_JSON`, …) means arm 1 — the failure was
  raised by core (or the bundle's own core-style exceptions) with its status,
  `source`, and stable code already set. The catalogue of these lives in core
  [errors-and-exceptions](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md).
- **No `code`, just a reason-phrase `title`** means arm 2 (a Symfony HTTP exception)
  or arm 3 (`Internal Server Error`, the generic 500). These are the bundle's
  status-keyed shapes for failures that originated outside core's vocabulary.

## Localizing and overriding error copy

Every error's `title` and `detail` are message templates core resolves per stable
error `code`
([core ADR 0128](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md#localizing-and-overriding-error-copy)).
The bundle binds that seam to the **Symfony translator** automatically: when
`symfony/translation` is installed, any error's copy can be localized or rebranded
through ordinary translation files in the `jsonapi_errors` domain, keyed by code:

```yaml
# translations/jsonapi_errors.fr.yaml
RESOURCE_NOT_FOUND:
    title: Ressource introuvable
MEDIA_TYPE_UNSUPPORTED:
    detail: "Le type de média '{mediaType}' n'est pas supporté."
VALIDATION_FAILED:
    title: Entité non traitable
```

Only the human copy moves: an error's `code` and `status` are never touched, and a
key you don't provide falls back to core's inline English — per slot, so a partial
translation is fine. The values are **templates**: a `{placeholder}` is filled from
the error's context *after* translation (a media type, an id), so write `{mediaType}`,
not Symfony's `%mediaType%` (no parameters are passed to the translator). The
placeholder names available per code are listed in core's
[errors-and-exceptions](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md#localizing-and-overriding-error-copy).

The lookup uses the translator's current locale, so `Accept-Language` negotiation is
the framework's job — wire Symfony's usual locale resolution and each request renders
in its negotiated language. Because the resolver is applied uniformly to every error,
the validator's `422` `VALIDATION_FAILED` title (arm 1) localizes through the very same
domain. Without `symfony/translation` the seam is inert and errors render in English
exactly as before.

## Next / see also

- [lifecycle](lifecycle.md) — the success path this listener mirrors:
  `kernel.request` → `JsonApiController` → `kernel.view`, and where negotiation
  raises the 415/406/400 errors this listener then renders.
- [routing](routing.md) — where the `_jsonapi` route marker (and `_jsonapi_server`)
  are stamped, and why an unknown type 404s at the router before this listener.
- [validation](validation.md) — how a write failure becomes the `422`
  `VALIDATION_FAILED` document arm 1 renders.
- [multi-server-and-testing](multi-server-and-testing.md) — how `_jsonapi_server`
  resolves to the per-server `Server` the error document is rendered against.
- Core [errors-and-exceptions](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md)
  (the exception catalogue, `Error`/`ErrorSource`, `ErrorResponse`,
  `InternalServerError::for`, and `JsonApiExceptionInterface`).
