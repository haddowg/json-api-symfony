# The request lifecycle runs as Symfony kernel listeners over Server::dispatch()

Rather than run `$server->handle()` in a controller and reuse core's PSR-15
middleware suite, the bundle re-expresses the lifecycle as kernel listeners:
`kernel.request` negotiates, parses, resolves the `Target` into an operation and
calls **`Server::dispatch()`**; `kernel.view` renders the response value object;
`kernel.exception` renders errors (see
[ADR 0003](0003-json-api-routes-render-all-errors-as-documents.md)). Core's
`Middleware\*` classes are never instantiated.

This buys idiomatic Symfony integration — the profiler, `kernel.exception`, native
logging, and the firewall all wrap the flow — at the cost of depending on core's
lifecycle *logic* being reusable without the middleware wrappers (confirmed public;
any gap is fixed in core). We accept keeping the listener path in step with core's
lifecycle while core is pre-1.0.
