# The request lifecycle runs as Symfony kernel listeners over `Server::dispatch()`

The bundle does not run core's PSR-15 middleware: `kernel.request` negotiates, parses and resolves the `Target` into an operation and calls `Server::dispatch()`, `kernel.view` renders the response value object, and `kernel.exception` renders errors, so no `Middleware\*` class is ever instantiated. This lets the profiler, the firewall, `kernel.exception` and Symfony's logger wrap the whole flow, at the cost of depending on core's lifecycle logic being callable without its middleware wrappers; a gap there is fixed in core rather than worked around here.

## Consequences

The request listener runs after the firewall (and after the router), because lifecycle hooks and security expressions fire from it and need the authenticated token.
