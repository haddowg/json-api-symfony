# Extensible exception → JSON:API-error mapping seam

The route-scoped `ExceptionListener` previously rendered errors through a fixed
cascade (core `JsonApiExceptionInterface` → Symfony `HttpExceptionInterface` →
Security → `500`), so an app could only map its own domain / third-party exceptions
by decorating the whole listener. We added a seam with two author-facing facets: a
tagged `ExceptionMapperInterface` (a service returns an `ErrorResponse` or `null` to
defer; priority-ordered via the `json_api.exception_mapper` tag) for rich errors,
and a `json_api.exceptions` config map (exception-class FQCN → HTTP status) read by
a built-in `ConfiguredExceptionMapper` for the common status-only case. The mappers
are consulted only **after** the core arm and only for a throwable that is not a
`JsonApiExceptionInterface` — the invariant being that a core JSON:API exception
always renders natively and is never intercepted or overridden by a mapper or the
config map. The config mapper registers at a low tag priority (`-1000`) so an
application's own mappers (default `0`) win over the config map, and when several
mapped classes match a throwable (a subclass hierarchy) the most-derived one wins.
