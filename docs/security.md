# Security & deployment posture

This page covers the bundle-side security and deployment surface: where your
firewall sits relative to JSON:API routes, what debug output is gated in
production, and the guarantees the bundle does — and does not — make. It is a
short page by design; the spec-level posture (PII in error documents, the JSON:API
security considerations) is owned by core's
[security doc](https://github.com/haddowg/json-api/blob/main/docs/security.md).

## The bundle adds no auth of its own

The bundle ships **no** authentication or authorization. JSON:API endpoints are
ordinary Symfony routes emitted by the route loader (see [routing](routing.md)),
so you place a firewall and authenticators in `security.yaml` exactly as you would
for any route — per path or host pattern, with `access_control` rules, voters, or
`#[IsGranted]` on whatever you layer in front.

Two properties of the route loader make this work cleanly:

- **Routes are literal, not a catch-all.** The loader emits one concrete path per
  type and operation (`GET /albums`, `POST /albums`, `GET /albums/{id}`, …) — it
  does **not** register a single parametric `/{type}` catch-all. So `access_control`
  patterns and per-route firewalls match JSON:API paths the same way they match any
  other route; the router itself `404`s an unknown type before security runs.
- **Multi-server prefixes are natural firewall boundaries.** When you mount a named
  server under a prefix (the example app mounts an `admin` server under `/admin` —
  see [multi-server-and-testing](multi-server-and-testing.md)), a `^/admin` firewall
  or `access_control` rule scopes that whole server in one line:

```yaml
# config/packages/security.yaml (your app — the bundle ships none)
security:
    firewalls:
        admin_api:
            pattern: ^/admin
            # … your authenticator(s)
    access_control:
        - { path: ^/admin, roles: ROLE_ADMIN }
```

The example app intentionally ships no firewall (it has nothing to protect), so the
recipes here are prose — but the mechanism is exactly Symfony's, unchanged.

## Declarative per-resource authorization (optional)

On top of the firewall, the bundle offers an **optional** declarative-authorization
layer: a resource declares Symfony Security expressions on `#[AsJsonApiResource]`
(`security:`, `securityCreate:`, …) and the bundle evaluates them at the right
lifecycle hook for each operation — denying a write *before* it persists, gating a
single read, delegating `is_granted('EDIT', object)` to a Voter. It activates only
when `symfony/security-core` is installed and a firewall is configured. See
[authorization](authorization.md) for the full surface.

## Firewall failures still render as JSON:API

Because the route-scoped `ExceptionListener` (see [errors](errors.md)) maps any
Symfony `HttpExceptionInterface` to a status-keyed JSON:API error, a security
exception on a JSON:API route renders as a **spec-compliant error document**, not
an HTML login redirect:

| Thrown by the firewall | Status | Rendered error title |
| --- | --- | --- |
| `UnauthorizedHttpException` (no credentials) | `401` | `Unauthorized` |
| `AccessDeniedHttpException` (denied) | `403` | `Forbidden` |
| `AccessDeniedException` (Security, e.g. the declarative layer) | `403` (or `401` when unauthenticated) | `Forbidden` / `Unauthorized` |
| `AuthenticationException` (Security) | `401` | `Unauthorized` |

The last two are Symfony Security exceptions that are **not** `HttpExceptionInterface`,
so the listener maps them explicitly (guarded so it compiles without
`symfony/security-core`) — this is what lets the declarative-authorization layer
([authorization](authorization.md)) render a clean `403`/`401`.

So authentication and authorization compose with JSON:API rendering for free. The
one thing to configure on your side: point the firewall's `entry_point` and
`access_denied_handler` at handlers that **return** the relevant `HttpException`
(or a `vnd.api+json` response) rather than redirecting to a login form — otherwise
an unauthenticated API client gets a `302` to HTML. The listener owns the body once
the exception is thrown; the firewall owns what gets thrown.

The error model the mapping rides on is core's — see
[errors-and-exceptions](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md).

## Debug-meta gating — a production checklist item

The `ExceptionListener`'s `$debug` flag is bound from `%kernel.debug%` in the
bundle's service config:

```php
// config/services.php
$services->set(ExceptionListener::class)
    ->args([
        // …
        '$debug' => '%kernel.debug%',
        '$logger' => service('logger')->nullOnInvalid(),
    ])
    ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => 128]);
```

With debug **off** (production), the listener redacts everything beyond the spec's
stable error fields:

- A generic `500` carries **no** `{exception, file, line, trace}` `meta`, no `detail`,
  and no `code` — byte-identical to what core's own middleware would emit
  (`InternalServerError::for($throwable, false)`).
- An `HttpException`-derived error (e.g. a `403`) carries **no** `detail` — the
  underlying message is suppressed (`detail: $this->debug ? $throwable->getMessage() : ''`).

With debug **on**, the `{exception, file, line, trace}` `meta` and the `detail`
render — useful in `dev`, a leak in `prod`. This is exercised both ways in the
bundle's `ExceptionListenerTest` and witnessed end to end (debug off) by the
example app's
[`ErrorHandlingTest::errorDocumentsLeakNoDebugMetaWithDebugOff()`](../examples/music-catalog-symfony/tests/ErrorHandlingTest.php).

**The single rule: ship production with `APP_ENV=prod` so `kernel.debug` is `false`.**
That one setting closes the entire debug-leak surface — there is no separate bundle
flag to remember.

A related kernel setting worth turning on so the listener actually owns every
failure: `framework.handle_all_throwables: true` (the example app sets it) routes
every throwable through `kernel.exception`, so even a non-`HttpException` becomes a
JSON:API `500` rather than a framework HTML error page.

## Request body size — capped at the edge, not by the bundle

The bundle imposes **no** request-body-size limit. On a write verb the lifecycle
reads the body and `json_decode`s it as core negotiates (see
[lifecycle](lifecycle.md)); a hostile multi-megabyte body is parsed into memory like
any other. Cap request size where Symfony apps always do — at the edge:

- the web server / reverse proxy (`client_max_body_size` in nginx, `LimitRequestBody`
  in Apache), and/or
- PHP's `post_max_size` / `memory_limit`.

The optional opis structural linter (`json_api.schema_validation`, see
[validation](validation.md)) runs **after** the body is decoded — it validates
document shape, not size, so it is not a size guard.

## Query columns are server-side only

Filter and sort columns never come from the client. The Doctrine reference layer
(see [doctrine](doctrine.md)) takes column names from the **server-side resource
declaration**, regex-validates each as a Doctrine field path before interpolating
it into DQL, and always binds the value as a query parameter — so a client cannot
inject a column name or a value into the query. The bundle's own bound-parameter
placeholders are namespaced with a reserved `jsonapi_` prefix to stay
collision-free with an entity's own mappings. Filter/sort *parameter keys* the
client supplies are matched against the declared vocabulary and rejected with a
`400` (`FILTERING_UNRECOGNIZED` / `SORTING_UNRECOGNIZED`) when unknown.

## What the bundle guarantees — and what it doesn't

| The bundle guarantees | The bundle does NOT provide (you own it) |
| --- | --- |
| Route-scoping — it never hijacks non-JSON:API routes (the `ExceptionListener` acts only on routes carrying `_jsonapi`) | Authentication / authorization (your firewall + voters) |
| Debug-meta redaction in production (`%kernel.debug%`-gated) | Rate limiting (e.g. `symfony/rate-limiter`) |
| Server-side-only, parameter-bound filter/sort columns | Request body-size limits (the edge / PHP) |
| Spec-compliant error documents for firewall `401`/`403` | CORS (e.g. `nelmio/cors-bundle`) |
| | CSRF (not applicable to stateless token APIs; your concern if you run cookie sessions) |

Everything in the right column is a standard Symfony or edge concern, deliberately
left to you — the bundle does not reinvent the framework's security stack, it slots
into it.

## Next / See also

- [errors](errors.md) — the route-scoped `ExceptionListener`, the full status
  mapping table, and the debug gating in detail.
- [routing](routing.md) — why the routes are literal (so `access_control` matches
  them) and the route-defaults contract.
- [multi-server-and-testing](multi-server-and-testing.md) — per-server prefixes as
  firewall boundaries.
- Core [security](https://github.com/haddowg/json-api/blob/main/docs/security.md) —
  the spec-level posture (PII in errors, JSON:API security considerations) the bundle
  inherits.
