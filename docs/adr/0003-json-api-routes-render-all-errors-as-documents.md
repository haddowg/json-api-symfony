# JSON:API routes render every error as a JSON:API document

A JSON:API endpoint must answer every failure, including a firewall `401`/`403`, a routing `404`/`405` and an uncaught `500`, with an error document, without disturbing the rest of a mixed application. So the `kernel.exception` listener is route-scoped: it acts only on a route carrying the bundle's marker, and there it owns every error. A core `JsonApiExceptionInterface` always renders natively through core's mapping and can never be intercepted; any other throwable goes to the tagged `ExceptionMapperInterface`s and the `json_api.exceptions` status map (an application's mappers outrank the config map, and the most-derived mapped class wins), then a Symfony `HttpExceptionInterface` by its status, then a `500`.

## Consequences

The router refuses a method before any route matches, so for a `405` the listener re-matches the path under each allowed method and answers only when one of those routes carries the marker. Matching the path against each server's mount prefix was rejected because a prefix also covers the application's own routes beneath it.
